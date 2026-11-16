# Telegram button workflow

2026-11-16, Asia/Tehran. The owner requested routine bot choices without typing.

New Telegram replies include inline reports/help, a pending-price-question entry and
a link to the existing WordPress owner dashboard. Price questions offer confirmation,
rejection and correction; missing prices omit confirmation. Correction opens a forced
reply requesting only the number in toman. Typed commands/answers remain compatible;
Bale's existing text path is retained. The original blocked enrollment posts were not
retained; one newly requested Staging enrollment was approved with an accepted WordPress
audit receipt. Personal chat IDs and receipts remain private, outside Git.

Confirmation data is a short HMAC bound to the bot, chat, prompt ID, question and action.
The webhook authenticates its Telegram secret, checks the approved private sender and
validates the current prompt inside the existing publication lock. Older/changed
questions and duplicate confirmation clicks cannot resolve the next item. Completed
buttons are removed. Price-entry context is small, expires after 15 minutes and cannot
be applied to a newer question. Publication reuses the durable Sheet-projection queue.
No poller, additional always-running process, LLM call or pricing policy is introduced.

The focused tests mock every Telegram/Sheet write. They cover short signed callbacks,
chat/action/price binding, missing price, expired/replayed input, sender authorization,
report/pending-question buttons, confirmation replay, rejection, forced numeric entry,
channel isolation and callback delivery registration without dropping pending updates.
Existing inbound, pipeline and outbox regression suites remain applicable.
