"""Build a self-contained, static review artifact. No live shop APIs or writes."""
from __future__ import annotations
import base64
import json
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SOURCE = Path(__file__).resolve().parent
sys.path.insert(0, str(ROOT))
from catalog.pricing import price_band

# Illustrative fixtures, not the live inventory or its supplier costs.
FIXTURES = [
    ('tea', 'چای کیسه‌ای شهرزاد', '۲۵ عددی', 'بنکدار جوان', 'چای و دمنوش', 30000, 100000, 1, 'tea.jpg'),
    ('energy', 'نوشیدنی انرژی‌زا', 'یک لیتری', 'پخش رحیمی', 'نوشیدنی', 30833, 60000, 6, 'energy-liter.jpg'),
    ('tuna', 'کنسرو تن ماهی', '۱۸۰ گرم', 'بنکدار جوان', 'کنسرو', 40000, 50000, 6, 'tuna.jpg'),
    ('paste', 'رب گوجه‌فرنگی', '۸۰۰ گرم', 'پخش مولوی', 'کنسرو', 40000, 80000, 1, 'paste.jpg'),
    ('pasta', 'ماکارونی رشته‌ای', '۷۰۰ گرم', 'پخش مولوی', 'مواد اولیه', 22000, 48000, 6, 'pasta.jpg'),
    ('rosewater', 'گلاب طبیعی', 'یک لیتری', 'پخش رحیمی', 'نوشیدنی', 45000, 100000, 1, 'rosewater.jpg'),
    ('cookie', 'بیسکویت کاکائویی', 'بسته‌ای', 'پخش مولوی', 'تنقلات', 30000, 70000, 1, 'cookie.jpg'),
    ('sugar', 'قند شکسته', 'بسته‌ای', 'پخش مولوی', 'مواد اولیه', 45000, 45000, 1, 'sugar.jpg'),
]

def data_uri(path: Path) -> str:
    mime = 'font/woff2' if path.suffix == '.woff2' else 'image/jpeg'
    return f'data:{mime};base64,' + base64.b64encode(path.read_bytes()).decode()

def public_data() -> dict:
    products = []
    for pid, name, pack, supplier, category, cost, regular, minimum, image in FIXTURES:
        band = price_band(cost, regular)
        products.append(dict(id=pid, name=name, pack=pack, supplier=supplier, category=category,
            regular=regular, floor=band['floor'], ceiling=band['ceiling'],
            maxDiscount=band['max_discount'], discountable=band['discountable'], min=minimum,
            image=data_uri(ROOT / 'shop/assets/products' / image)))
    return dict(version='2.0', unlockTarget=2000000, initialCart={'tea':4,'energy':10}, products=products)

def build(destination: Path) -> None:
    destination.mkdir(parents=True, exist_ok=True)
    data = public_data()
    css = (SOURCE / 'store.css').read_text()
    def embed(match):
        return 'url("' + data_uri((SOURCE / match.group(1)).resolve()) + '")'
    css = re.sub(r'url\([\"\']([^\"\']+)[\"\']\)', embed, css)
    page = (SOURCE / 'index.html').read_text().replace('<link rel="stylesheet" href="store.css">', '<style>'+css+'</style>')
    for image in ('tea.jpg', 'energy-liter.jpg'):
        page = page.replace('../../shop/assets/products/'+image, data_uri(ROOT / 'shop/assets/products' / image))
    page = page.replace('__DEMO_DATA__',json.dumps(data,ensure_ascii=False).replace('</','<\\/'))
    for script in ('pricing.js','store.js'):
        page = page.replace('<script src="'+script+'"></script>','<script>'+(SOURCE / script).read_text()+'</script>')
    (destination/'storefront.html').write_text(page)
    # Small fixture file for meaningful parity checks. Do not duplicate image bytes.
    (destination/'fixture.json').write_text(json.dumps({**data,'products':[{k:v for k,v in p.items() if k!='image'} for p in data['products']]},ensure_ascii=False,indent=2))
    (destination/'review.html').write_text((SOURCE/'review.html').read_text())
    print(json.dumps({'built':str(destination/'storefront.html'),'bytes':len(page.encode()),'products':len(data['products'])}))

if __name__=='__main__':
    build(Path(sys.argv[1]) if len(sys.argv)>1 else Path('/workspace/scratch/mockup-v2'))
