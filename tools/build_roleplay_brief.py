"""Build a public, credential-free handoff snapshot from the project documentation.

This builds files only. Publishing is a separate authorized operation.
"""
from __future__ import annotations
import html
import json
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
STEM = 'molavi-workflow-e73014a831'
SECTIONS = [
    ('handoff', 'Start here', 'project-handoff.md'),
    ('workflow', 'Whole workflow', 'workflow-roleplay.md'),
    ('migration', 'Migration audit', 'gas-migration-audit.md'),
    ('recovered', 'Recovered GAS audit', 'recovered-gas-audit.md'),
    ('recovered-inventory', 'Recovered GAS inventory', 'recovered-gas-inventory.md'),
    ('reference-access', 'Source checkpoint', 'reference-source-access.md'),
    ('inventory', 'GAS inventory', 'gas-migration-inventory.md'),
    ('roleplay', 'Customer appendix', 'roleplay-plan.md'),
    ('decisions', 'Decisions', 'decisions.md'),
    ('business', 'Business flow', 'business-flow.md'),
    ('design', 'Design notes', 'design-decisions.md'),
]

def inline(source: str) -> str:
    source = html.escape(source)
    source = re.sub(r'`([^`]+)`', r'<code>\1</code>', source)
    source = re.sub(r'\*\*([^*]+)\*\*', r'<strong>\1</strong>', source)
    # Only the known project links are converted. Arbitrary HTML stays escaped.
    for anchor, _, filename in SECTIONS:
        source = source.replace('href="'+filename+'"','href="#'+anchor+'"')
    return source

def markdown(source: str) -> str:
    """Small deterministic renderer for the Markdown subset in these authored docs."""
    lines = source.splitlines()
    blocks, paragraph, code = [], [], []
    list_type = None
    in_code = False
    table = []
    def flush():
        if paragraph:
            blocks.append('<p>'+inline(' '.join(paragraph))+'</p>');paragraph.clear()
    def close_list():
        nonlocal list_type
        if list_type:
            blocks.append('</'+list_type+'>');list_type=None
    def close_table():
        if not table:return
        rows=[x for x in table if not re.fullmatch(r'[\s|:\-]+',x)]
        blocks.append('<div class="table-wrap"><table>')
        for i,row in enumerate(rows):
            cells=[c.strip() for c in row.strip().strip('|').split('|')]
            tag='th' if i==0 else 'td'
            blocks.append('<tr>'+''.join('<'+tag+'>'+inline(c)+'</'+tag+'>' for c in cells)+'</tr>')
        blocks.append('</table></div>');table.clear()
    for line in lines:
        if line.startswith('```'):
            flush();close_list();close_table()
            if in_code:blocks.append('<pre><code>'+html.escape('\n'.join(code))+'</code></pre>');code.clear()
            in_code=not in_code;continue
        if in_code:code.append(line);continue
        if line.startswith('|'):
            flush();close_list();table.append(line);continue
        close_table()
        if not line.strip():flush();close_list();continue
        match=re.match(r'^(#{1,4})\s+(.+)',line)
        if match:
            flush();close_list();level=min(4,len(match[1])+1)
            blocks.append(f'<h{level}>'+inline(match[2])+f'</h{level}>');continue
        match=re.match(r'^(- |\d+\. )(.*)',line)
        if match:
            flush();desired='ul' if match[1]=='- ' else 'ol'
            if list_type!=desired:
                close_list();blocks.append('<'+desired+'>');list_type=desired
            blocks.append('<li>'+inline(match[2])+'</li>');continue
        if list_type:close_list()
        paragraph.append(line.strip())
    flush();close_list();close_table()
    return '\n'.join(blocks)

def build(destination: Path) -> list[Path]:
    destination.mkdir(parents=True,exist_ok=True)
    state=json.loads((ROOT/'docs/roleplay-state.json').read_text())
    sections=[(anchor,title,(ROOT/'docs'/filename).read_text()) for anchor,title,filename in SECTIONS]
    exported='# MolaviSale shared project brief\n\nSnapshot '+state['snapshot_version']+'. Current scene: '+state['current_scene']+'.\n\n'
    exported+='\n\n---\n\n'.join(text for _,_,text in sections)
    exported+='\n\n---\n\n# Roleplay state\n\n```json\n'+json.dumps(state,ensure_ascii=False,indent=2)+'\n```\n'
    nav=''.join('<a href="#'+a+'">'+t+'</a>' for a,t,_ in sections)+'<a href="#state">State</a>'
    body=''.join('<section id="'+a+'">'+markdown(t)+'</section>' for a,_,t in sections)
    body+='<section id="state"><h2>Roleplay state</h2><pre><code>'+html.escape(json.dumps(state,ensure_ascii=False,indent=2))+'</code></pre></section>'
    page='''<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow,noarchive"><title>MolaviSale · Roleplay and project brief</title><style>*{box-sizing:border-box}html{scroll-behavior:smooth;scroll-padding-top:100px}body{margin:0;background:#f6f7f8;color:#26333c;font:15px/1.75 system-ui,-apple-system,sans-serif}header{background:#fff;border-bottom:1px solid #e1e5e8;padding:23px max(20px,calc((100% - 1080px)/2))}header h1{font-size:24px;letter-spacing:-.7px;margin:0}header p{font-size:13px;color:#64717b;margin:7px 0 14px}.actions{display:flex;gap:9px;flex-wrap:wrap}.actions a{padding:8px 12px;border:1px solid #dce3e7;border-radius:7px;text-decoration:none;color:#245b53;font-size:12px}.actions a:first-child{background:#245b53;color:white}nav{position:sticky;top:0;display:flex;gap:18px;overflow:auto;white-space:nowrap;padding:13px max(20px,calc((100% - 1080px)/2));border-bottom:1px solid #e1e5e8;background:#fffffff5;z-index:2}nav a{color:#455760;text-decoration:none;font-size:12px}main{max-width:1120px;margin:auto;padding:0 20px 60px}section{background:white;border:1px solid #e1e5e8;border-radius:12px;padding:26px 32px;margin-top:24px}h2{font-size:24px;letter-spacing:-.5px;margin:0 0 17px}h3{font-size:18px;margin:26px 0 9px;color:#245b53}h4{font-size:15px;margin:23px 0 8px}p{margin:10px 0}li{padding-left:4px;margin:6px 0}strong{color:#213039}code{background:#f0f3f5;padding:2px 5px;border-radius:4px;font-size:12px;overflow-wrap:anywhere}pre{background:#f0f3f5;padding:17px;border-radius:8px;overflow:auto;font-size:12px;line-height:1.6}pre code{padding:0;white-space:pre}.table-wrap{overflow-x:auto;margin:18px 0}table{border-collapse:collapse;width:100%;font-size:12px}th,td{padding:10px 12px;text-align:left;vertical-align:top;border:1px solid #e1e5e8;min-width:110px}th{background:#f4f7f7}td code{font-size:11px}.note{background:#eaf4f0;color:#245b53;border-radius:8px;padding:13px;font-size:12px}a:focus-visible{outline:3px solid #d25a31;outline-offset:4px}@media(max-width:650px){body{font-size:14px}header h1{font-size:21px}main{padding-inline:10px}section{padding:20px 16px;border-radius:9px}h2{font-size:21px}h3{font-size:17px}nav{gap:18px;padding:13px 20px}.actions a{font-size:11px}}</style></head><body><header><h1>MolaviSale · Roleplay and project brief</h1><p>Snapshot VERSION · Current scene SCENE · Persona decision pending</p><div class="actions"><a href="STEM.md">Read / download complete Markdown</a><a href="STEM.json">Machine-readable checkpoint</a><a href="molavi-design-preview-e73014a831.html">First mockup</a></div></header><nav>NAV</nav><main><p class="note">For another chat: share this page or its Markdown export. Chats do not automatically share history or update this snapshot. Mockup first; production store unchanged.</p>BODY</main></body></html>'''
    page=page.replace('VERSION',state['snapshot_version']).replace('SCENE',state['current_scene']).replace('STEM',STEM).replace('NAV',nav).replace('BODY',body)
    if state.get('persona'):
        page = page.replace('Persona decision pending', html.escape(state['status'].replace('_',' ')))
    files={STEM+'.html':page,STEM+'.md':exported,STEM+'.json':json.dumps(state,ensure_ascii=False,indent=2),STEM+'-v'+state['snapshot_version'].replace('.','-')+'.md':exported}
    for name,value in files.items():(destination/name).write_text(value)
    print(json.dumps({'built':[str(destination/n) for n in files],'snapshot_version':state['snapshot_version'],'scene_count':len(state['scene_order'])}))
    return [destination/n for n in files]

if __name__=='__main__':
    build(Path(sys.argv[1]) if len(sys.argv)>1 else Path('/workspace/scratch/roleplay-brief'))
