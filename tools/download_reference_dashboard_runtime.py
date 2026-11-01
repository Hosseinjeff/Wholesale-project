import urllib.request, urllib.error, zipfile, io, argparse
from pathlib import Path
parser=argparse.ArgumentParser()
parser.add_argument('--work-dir',type=Path,default=Path(__file__).resolve().parent.parent/'work/wp-reference-runtime')
root=parser.parse_args().work_dir.resolve()
for kind,urls in [('core',['https://wordpress.org/wordpress-7.1.3.zip']),('woocommerce',['https://downloads.wordpress.org/plugin/woocommerce.11.2.1.zip'])]:
 for url in urls:
  try:
   with urllib.request.urlopen(url,timeout=40) as r: data=r.read(80_000_000)
   archive=zipfile.ZipFile(io.BytesIO(data)); break
  except urllib.error.HTTPError as e:
   if e.code != 404:raise
 else:raise RuntimeError('Official package unavailable')
 target=root if kind=='core' else root/'wordpress/wp-content/plugins'
 target.mkdir(parents=True,exist_ok=True)
 for member in archive.infolist():
  if not (target/member.filename).resolve().is_relative_to(target.resolve()): raise RuntimeError('Invalid archive path')
 archive.extractall(target)
 print(kind+': downloaded '+url,flush=True)
