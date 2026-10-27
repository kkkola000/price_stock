// Development instance only; restores the previous effective timezone.
const {chromium}=require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const fs=require('fs'),assert=require('assert/strict'),{execFileSync}=require('child_process'),path=require('path');
const base=(process.env.YML_TEST_BASE_URL || 'http://127.0.0.1:8080').replace(/\/$/,'');
(async()=>{const browser=await chromium.launch({executablePath:process.env.BROWSER_PATH || '/usr/bin/chromium',args:['--no-sandbox']});let p,original;
try {
 p=await browser.newPage();await p.goto(base+'/admin/index.php?page=login');await p.locator('[name=username]').fill(process.env.YML_TEST_USER || 'devadmin');await p.locator('[name=password]').fill(fs.readFileSync(process.env.YML_TEST_PASSWORD_FILE,'utf8').trim());await p.locator('button[type=submit]').click();await p.getByRole('link',{name:'Настройки',exact:true}).click();
 original=await p.locator('[name=timezone]').inputValue();
 await p.locator('[name=timezone]').selectOption('UTC');await Promise.all([p.waitForNavigation(),p.getByRole('button',{name:'Сохранить часовой пояс',exact:true}).click()]);assert.equal(await p.locator('[name=timezone]').inputValue(),'UTC');
 const result=JSON.parse(execFileSync(process.env.PHP_BIN || 'php',['-r',`require 'app/bootstrap.php'; echo json_encode(['php'=>date_default_timezone_get(),'config'=>App\\Support\\Config::get('timezone'),'db'=>App\\Support\\Db::scalar('SELECT @@session.time_zone'),'date'=>date('Y-m-d H:i')]);`],{cwd:path.resolve(__dirname,'..'),encoding:'utf8'}));
 assert.equal(result.php,'UTC');assert.equal(result.config,'UTC');assert.equal(result.db,'+00:00');assert(Math.abs(Date.parse(result.date.replace(' ','T')+':00Z')-Date.now())<120000);
 const csrf=await p.locator('[name=csrf_token]').inputValue();await p.request.post(base+'/admin/index.php?page=settings',{form:{csrf_token:csrf,timezone:'Invalid/Zone'}});await p.reload();assert.equal(await p.locator('[name=timezone]').inputValue(),'UTC');
 console.log('PASS UI: navigation, timezone save/reload, CLI/config/PHP/DB session agreement, invalid value rejected');
}finally{
 if(p&&original){await p.goto(base+'/admin/index.php?page=settings');await p.locator('[name=timezone]').selectOption(original);await Promise.all([p.waitForNavigation(),p.getByRole('button',{name:'Сохранить часовой пояс',exact:true}).click()]);}
 await browser.close();
}})().catch(e=>{console.error(e);process.exit(1)});
