import os, subprocess, time, argparse, uuid
from pathlib import Path
repo=Path(__file__).resolve().parent.parent
parser=argparse.ArgumentParser()
parser.add_argument('--work-dir',type=Path,default=repo/'work/wp-reference-runtime')
root=parser.parse_args().work_dir.resolve()
env=dict(os.environ)
for key in ('DOCKER_HOST','DOCKER_CONTEXT','DOCKER_TLS','DOCKER_TLS_VERIFY','DOCKER_CERT_PATH'):env.pop(key,None)
def docker(*args,check=True):
 r=subprocess.run(['docker','--host=unix:///var/run/docker.sock',*args],env=env,text=True,capture_output=True)
 if check and r.returncode:raise RuntimeError(r.stderr[-2000:]+r.stdout[-1000:])
 return r
network='molavi-reference-'+uuid.uuid4().hex[:8]; db=network+'-db'
def wp(*args):
 r=docker('run','--rm','--network',network,'--user','0',
  '--mount',f'type=bind,src={root}/wordpress,dst=/var/www/html',
  '--mount',f'type=bind,src={repo}/shop-wp/molavi-shop,dst=/plugin,readonly',
  '--mount',f'type=bind,src={repo}/tests/reference_dashboard_runtime.php,dst=/runtime.php,readonly',
  'wordpress:cli-php8.3','wp',*args,'--allow-root')
 return r.stdout
try:
 docker('network','create','--internal',network)
 docker('run','-d','--name',db,'--network',network,
        '-e','MARIADB_ROOT_PASSWORD=synthetic-local-root','-e','MARIADB_DATABASE=reference_test',
        '-e','MARIADB_USER=reference_test','-e','MARIADB_PASSWORD=synthetic-local-password','mariadb:11.4')
 for attempt in range(30):
  r=docker('exec',db,'healthcheck.sh','--connect','--innodb_initialized',check=False)
  if r.returncode==0:break
  time.sleep(1)
 else:raise RuntimeError('Disposable DB not ready')
 wp('config','create','--dbname=reference_test','--dbuser=reference_test',
    '--dbpass=synthetic-local-password','--dbhost='+db,'--skip-check','--force')
 wp('core','install','--url=http://reference-test.invalid','--title=Reference test',
    '--admin_user=reference_test_owner','--admin_password=synthetic-local-owner',
    '--admin_email=owner@example.invalid','--skip-email')
 print(wp('plugin','activate','woocommerce'),flush=True)
 report=wp('eval-file','/runtime.php')
 (root/'runtime-results.txt').write_text(report)
 print(report,flush=True)
finally:
 docker('rm','-f',db,check=False)
 docker('network','rm',network,check=False)
