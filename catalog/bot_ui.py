"""Stateless, prompt-bound Telegram buttons; only price-entry context is durable."""
from __future__ import annotations
import hashlib
import hmac
import json
import os
import re
import sqlite3
import time

PRICE_ENTRY_PREFIX='قیمت واحد جدید'

def menu_keyboard():
    rows=[[{'text':'وضعیت فروشگاه','callback_data':'mv:menu:store'},{'text':'سفارش‌ها','callback_data':'mv:menu:orders'}],
          [{'text':'کالاها','callback_data':'mv:menu:products'},{'text':'راهنما','callback_data':'mv:menu:help'}]]
    rows.append([{'text':'پرسش‌های قیمت','callback_data':'mv:menu:review'}])
    site=(os.getenv('SHOP_SITE_URL') or '').rstrip('/')
    if site.startswith('https://'):rows.append([{'text':'بررسی‌ها در داشبورد','url':site+'/wp-admin/admin.php?page=molavi-operations'}])
    return {'inline_keyboard':rows}

def public_prompt(row):
    return {'prompt_id':str(row.get('Prompt ID') or row.get('prompt_id') or ''),
            'chat_id':str(row.get('Chat ID') or row.get('chat_id') or ''),
            'question':str(row.get('Question') or row.get('question') or ''),
            'name':str(row.get('Product Name') or row.get('name') or ''),'reason':str(row.get('Reason') or row.get('reason') or '')}

def prompt_signature(prompt,chat_id,secret,action='input'):
    value=public_prompt(prompt)
    body=json.dumps([str(chat_id),value['prompt_id'],value['question'],action],ensure_ascii=False,separators=(',',':'))
    return hmac.new(secret.encode(),('molavi-button-v1:'+body).encode(),hashlib.sha256).hexdigest()[:32]

def prompt_keyboard(prompt,chat_id,secret):
    if not prompt:return menu_keyboard()
    prompt=public_prompt(prompt)
    if prompt['chat_id']!=str(chat_id) or not prompt['prompt_id'] or not secret:return menu_keyboard()
    choices=[]
    if 'missing unit price' not in prompt['reason'] and 'قیمت واحد: پیدا نشد' not in prompt['question']:
        choices.append({'text':'تأیید همین قیمت','callback_data':'mv:ok:'+prompt_signature(prompt,chat_id,secret,'ok')})
    choices.append({'text':'رد این مورد','callback_data':'mv:no:'+prompt_signature(prompt,chat_id,secret,'no')})
    rows=[choices,[{'text':'اصلاح قیمت','callback_data':'mv:edit:'+prompt_signature(prompt,chat_id,secret,'edit')}]]
    return {'inline_keyboard':rows+menu_keyboard()['inline_keyboard']}

def valid_button(data,prompt,chat_id,secret):
    match=re.fullmatch(r'mv:(ok|no|edit):([a-f0-9]{32})',str(data or ''))
    if not match or not prompt:return None
    value=public_prompt(prompt)
    if value['chat_id']!=str(chat_id):return None
    action,digest=match.groups()
    return action if hmac.compare_digest(digest,prompt_signature(value,chat_id,secret,action)) else None

def current_prompt(store,chat_id):
    from catalog.sync import _prompt_records,_waiting_prompt
    return _waiting_prompt(_prompt_records(store),chat_id)

def button_text(text,keyboard):
    if not any(str(button.get('callback_data') or '').startswith('mv:ok:') for row in keyboard.get('inline_keyboard',[]) for button in row):return text
    return str(text).replace('قیمت واحد را بفرست، یا «باشه» برای تأیید، یا «رد».','از دکمه‌ها انتخاب کنید؛ برای تغییر قیمت «اصلاح قیمت» را بزنید.').replace('قیمت واحد را بفرست، یا «باشه» برای تأیید، یا «رد».\n\n','از دکمه‌ها انتخاب کنید.\n\n')

def remember_price_entry(queue,chat_id,prompt,secret,message_id):
    with queue.connect() as db:
        db.execute('CREATE TABLE IF NOT EXISTS bot_price_inputs (chat_id TEXT PRIMARY KEY,prompt_token TEXT NOT NULL,message_id TEXT NOT NULL,expires REAL NOT NULL)')
        db.execute('DELETE FROM bot_price_inputs WHERE expires<?',(time.time()-86400,))
        db.execute('INSERT OR REPLACE INTO bot_price_inputs VALUES (?,?,?,?)',(str(chat_id),prompt_signature(prompt,chat_id,secret),str(message_id),time.time()+900))

def clear_price_entry(queue,chat_id):
    if queue is None:return
    try:
        with queue.connect() as db:db.execute('DELETE FROM bot_price_inputs WHERE chat_id=?',(str(chat_id),))
    except sqlite3.OperationalError:pass

def price_reply_is_current(queue,chat_id,message,prompt,secret):
    reply=message.get('reply_to_message') or {}
    try:
        with queue.connect() as db:row=db.execute('SELECT * FROM bot_price_inputs WHERE chat_id=?',(str(chat_id),)).fetchone()
    except sqlite3.OperationalError:row=None
    if row and (not reply or str(reply.get('message_id'))==row['message_id']):
        return bool(prompt and row['expires']>=time.time() and hmac.compare_digest(row['prompt_token'],prompt_signature(prompt,chat_id,secret)))
    if str(reply.get('text') or '').startswith(PRICE_ENTRY_PREFIX):return False
    # A direct reply to an old button-bearing question must match the current question.
    buttons=[button for group in (reply.get('reply_markup') or {}).get('inline_keyboard',[]) for button in group]
    signed=[b.get('callback_data') for b in buttons if str(b.get('callback_data') or '').startswith(('mv:ok:','mv:no:','mv:edit:'))]
    return not signed or any(valid_button(data,prompt,chat_id,secret) for data in signed)
