import copy
import os
import tempfile
import unittest
from pathlib import Path
from unittest.mock import Mock,patch
from catalog.bot_ui import menu_keyboard,prompt_keyboard,prompt_signature,valid_button,remember_price_entry,price_reply_is_current,clear_price_entry
from catalog.durable import Handoffs

P={'prompt_id':'p:synthetic-offer','chat_id':'123','name':'کالای نمونه','question':'قیمت واحد: 50000؛ قیمت مصرف: 60000'}
SECRET='synthetic-bot-key'

class BotButtonTests(unittest.TestCase):
    def test_signed_buttons_are_short_and_cover_all_choices(self):
        keyboard=prompt_keyboard(P,'123',SECRET);buttons=[b for row in keyboard['inline_keyboard'] for b in row];signed=[b['callback_data'] for b in buttons if b.get('callback_data','').startswith(('mv:ok:','mv:no:','mv:edit:'))]
        self.assertEqual(len(signed),3)
        for data in signed:self.assertLessEqual(len(data.encode()),64);self.assertIsNotNone(valid_button(data,P,'123',SECRET))
    def test_buttons_bind_chat_price_question_and_action(self):
        data=prompt_keyboard(P,'123',SECRET)['inline_keyboard'][0][0]['callback_data']
        self.assertIsNone(valid_button(data,P,'456',SECRET));self.assertIsNone(valid_button(data,{**P,'question':'new price'},'123',SECRET));self.assertIsNone(valid_button(data.replace('mv:ok:','mv:no:'),P,'123',SECRET))
    def test_missing_price_only_offers_correction_or_rejection(self):
        keyboard=prompt_keyboard({**P,'reason':'missing unit price'},'123',SECRET)
        self.assertFalse(any(b.get('callback_data','').startswith('mv:ok:') for row in keyboard['inline_keyboard'] for b in row))
    def test_other_chat_gets_no_price_actions(self):
        self.assertFalse(any(b.get('callback_data','').startswith('mv:ok:') for r in prompt_keyboard(P,'456',SECRET)['inline_keyboard'] for b in r))
    def test_price_entry_is_explicit_and_expires_without_applying_to_next_prompt(self):
        with tempfile.TemporaryDirectory() as temp:
            q=Handoffs(str(Path(temp)/'q.sqlite'));remember_price_entry(q,'123',P,SECRET,99)
            message={'reply_to_message':{'message_id':99,'text':'قیمت واحد جدید برای کالا'}}
            self.assertTrue(price_reply_is_current(q,'123',message,P,SECRET));self.assertFalse(price_reply_is_current(q,'123',message,{**P,'prompt_id':'p:next'},SECRET))
            with q.connect() as db:db.execute('UPDATE bot_price_inputs SET expires=0')
            self.assertFalse(price_reply_is_current(q,'123',message,P,SECRET));clear_price_entry(q,'123');self.assertFalse(price_reply_is_current(q,'123',message,P,SECRET))
    def test_expired_price_entry_does_not_block_a_new_supplier_post(self):
        with tempfile.TemporaryDirectory() as temp:
            q=Handoffs(str(Path(temp)/'q.sqlite'));remember_price_entry(q,'123',P,SECRET,99)
            with q.connect() as db:db.execute('UPDATE bot_price_inputs SET expires=0')
            self.assertTrue(price_reply_is_current(q,'123',{'text':'کالای تازه با قیمت 75000'},None,SECRET))
    def test_reply_to_old_button_message_is_refused(self):
        with tempfile.TemporaryDirectory() as temp:
            q=Handoffs(str(Path(temp)/'q.sqlite'));message={'reply_to_message':{'reply_markup':prompt_keyboard(P,'123',SECRET)}}
            self.assertFalse(price_reply_is_current(q,'123',message,{**P,'question':'changed'},SECRET))
            self.assertTrue(price_reply_is_current(q,'123',message,P,SECRET))

class TelegramButtonRouteTests(unittest.TestCase):
    def setUp(self):
        import app
        self.app=app;self.temp=tempfile.TemporaryDirectory();self.addCleanup(self.temp.cleanup);self.queue=Handoffs(str(Path(self.temp.name)/'q.sqlite'))
        self.prompt=copy.deepcopy(P);self.store=Mock();self.response=Mock(status_code=200);self.response.json.return_value={'ok':True,'result':{'message_id':99}}
        patches=[patch.dict(os.environ,{'TELEGRAM_WEBHOOK_SECRET':'synthetic-hook-secret-abcdefghijklmnopqrstuvwxyz','PIPELINE_RUNTIME_MODE':'on_demand'}),patch('app.BOT_TOKEN',SECRET),patch('app.BALE_BOT_TOKEN',''),patch('app.USE_SHEETS',True),patch('app.handoff_queue',return_value=self.queue),patch('app._notice_chat_allowlists',return_value={'telegram':{'123'},'bale':set()}),patch('app.build_store',return_value=self.store),patch('app.requests.post',return_value=self.response),patch('app.ensure_telegram_webhook'),patch('app._start_pipeline_worker'),patch('catalog.bot_ui.current_prompt',side_effect=lambda *_:self.prompt),patch('app._queue_prompt_publication')]
        self.mocks=[p.start() for p in patches]
        for p in patches:self.addCleanup(p.stop)
        self.client=app.app.test_client()
    def click(self,action='ok',**changes):
        data='mv:'+action+':'+prompt_signature(P,'123',SECRET,action) if action!='menu' else 'mv:menu:products'
        callback={'id':'synthetic-callback','from':{'id':123},'data':data,'message':{'message_id':10,'chat':{'id':123,'type':'private'}}};callback.update(changes)
        return self.client.post('/webhook',json={'callback_query':callback},headers={'X-Telegram-Bot-Api-Secret-Token':'synthetic-hook-secret-abcdefghijklmnopqrstuvwxyz'})
    def test_unauthorized_callback_never_reads_or_writes_sheet(self):
        with patch('app.resolve_prompt') as resolve:
            response=self.click(**{'from':{'id':456}})
        self.assertEqual(response.json['status'],'ignored');self.app.build_store.assert_not_called();resolve.assert_not_called()
    def test_stale_callback_does_not_resolve_a_new_question(self):
        self.prompt={**P,'question':'changed price'}
        with patch('app.resolve_prompt') as resolve:response=self.click()
        self.assertEqual(response.json['status'],'stale');resolve.assert_not_called()
    def test_confirmation_replay_cannot_confirm_the_next_item(self):
        def resolve(*args):self.prompt=None;return {'matched':True,'understood':True,'reply':'ثبت شد'}
        with patch('app.resolve_prompt',side_effect=resolve) as resolved:
            first=self.click();second=self.click()
        self.assertEqual(first.json['status'],'resolved');self.assertEqual(second.json['status'],'stale');resolved.assert_called_once();self.app._queue_prompt_publication.assert_called_once()
    def test_reject_reuses_existing_resolver(self):
        with patch('app.resolve_prompt',return_value={'matched':True,'understood':True,'reply':'رد شد'}) as resolve:self.click('no')
        self.assertEqual(resolve.call_args.args[2],'رد')
    def test_price_button_opens_forced_numeric_reply_without_price_write(self):
        with patch('app.resolve_prompt') as resolve:response=self.click('edit')
        self.assertEqual(response.json['status'],'needs_price');resolve.assert_not_called()
        sends=[c.kwargs['json'] for c in self.app.requests.post.call_args_list if c.args[0].endswith('/sendMessage')]
        self.assertTrue(sends[-1]['reply_markup']['force_reply']);self.assertEqual(sends[-1]['reply_markup']['input_field_placeholder'],'قیمت واحد به تومان')
    def test_report_button_requires_no_command_typing(self):
        with patch('app.reply_for_command',return_value='گزارش') as report:response=self.click('menu')
        self.assertEqual(response.json['status'],'command');report.assert_called_once_with('products',self.store)
    def test_pending_question_is_available_from_a_menu_button(self):
        with patch('app.resolve_prompt') as resolve:response=self.click(data='mv:menu:review')
        self.assertEqual(response.json['command'],'review');resolve.assert_not_called()
        sends=[c.kwargs['json'] for c in self.app.requests.post.call_args_list if c.args[0].endswith('/sendMessage')]
        self.assertTrue(sends[-1]['reply_markup']['inline_keyboard'][0][0]['callback_data'].startswith('mv:ok:'))
    def test_webhook_includes_callback_delivery_without_dropping_pending_updates(self):
        with patch('app.webhook_url_from_env',return_value='https://runtime.test/webhook'),patch('app._register_bot_commands'):
            self.assertTrue(self.app.register_telegram_webhook())
        body=self.app.requests.post.call_args.kwargs['json'];self.assertIn('callback_query',body['allowed_updates']);self.assertFalse(body['drop_pending_updates'])
    def test_selected_channel_does_not_send_to_another_bot(self):
        with patch('app.BALE_BOT_TOKEN','another-token'):self.app.send_chat_text('123','گزارش',channel='telegram')
        self.app.requests.post.assert_called_once();self.assertTrue(self.app.requests.post.call_args.args[0].startswith('https://api.telegram.org/'))

if __name__=='__main__':unittest.main()
