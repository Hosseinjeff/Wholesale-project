
import requests
import json

WEBHOOK_URL = "https://wholesale-project-production.up.railway.app/webhook"

samples = [
    {
        "name": "@bonakdarjavan format",
        "payload": {
            "update_id": 10001,
            "message": {
                "message_id": 5001,
                "from": {"id": 123, "username": "tester"},
                "date": 1770905000,
                "text": "کنسرو ماهی ۱۸۰ گرمی تاپ\n✅\nقیمت هر باکس: ۱,۲۵۰,۰۰۰ تومان\nدونه ای: ۵۲,۰۰۰ تومان\nقیمت مصرف: ۶۵,۰۰۰ تومان\nتعداد در باکس: ۲۴ عددی\nموجود ✅",
                "forward_origin": {
                    "type": "channel",
                    "chat": {"id": -100123, "title": "Bonakdar Javan", "username": "bonakdarjavan"},
                    "message_id": 1234,
                    "date": 1770904000
                }
            }
        }
    },
    {
        "name": "@nobelshop118 format",
        "payload": {
            "update_id": 10002,
            "message": {
                "message_id": 5002,
                "from": {"id": 123, "username": "tester"},
                "date": 1770905100,
                "text": "کاپوچینو گوددی ۳۰ تایی\n: ۷۵/۰۰۰\n\nهات چاکلت ۲۰ تایی\n: ۶۵/۰۰۰\n\n📍 میدان محمدیه پلاک ۱۰",
                "forward_origin": {
                    "type": "channel",
                    "chat": {"id": -100456, "title": "Nobel Shop", "username": "nobelshop118"},
                    "message_id": 5678,
                    "date": 1770904100
                }
            }
        }
    }
]

for sample in samples:
    print(f"Sending {sample['name']}...")
    try:
        response = requests.post(WEBHOOK_URL, json=sample['payload'], timeout=15)
        print(f"Status: {response.status_code}")
        print(f"Response: {response.json()}")
    except Exception as e:
        print(f"Error: {e}")
    print("-" * 20)
