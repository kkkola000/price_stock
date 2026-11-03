// Development database only: creates and removes its own supplier, never imports it.
const {chromium}=require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const fs=require('fs'),path=require('path'),assert=require('assert/strict'),{execFileSync}=require('child_process');
const root=path.resolve(__dirname,'..'),base=process.env.YML_TEST_BASE_URL || 'http://127.0.0.1:8080';
const php=code=>execFileSync(process.env.PHP_BIN || 'php',['-r',"require 'app/bootstrap.php'; "+code],{cwd:root,encoding:'utf8'}).trim();
(async()=>{let id;const browser=await chromium.launch({executablePath:process.env.BROWSER_PATH || '/usr/bin/chromium',args:['--no-sandbox']});try{
id=Number(php("echo App\\Models\\SourceRepository::create(['name'=>'Column merge UI test','type'=>'csv','fetch_method'=>'url','source_url'=>'https://example.invalid/test.csv','mapping'=>['name'=>'B','sku'=>'F']]);"));assert(id>0);
const p=await browser.newPage();await p.goto(base+'/admin/index.php?page=login');await p.locator('[name=username]').fill(process.env.YML_TEST_USER || 'devadmin');await p.locator('[name=password]').fill(fs.readFileSync(process.env.YML_TEST_PASSWORD_FILE,'utf8').trim());await p.locator('button[type=submit]').click();
await p.goto(base+'/admin/index.php?page=source-form&id='+id);
const box=p.locator('input[type=checkbox][name="mapping[_merge_skip_empty]"]');
assert(await box.isChecked());await p.locator('[name="mapping[name]"]').fill('B,G,F');
for(const enabled of [false,true]){
 await box.setChecked(enabled);await Promise.all([p.waitForNavigation(),p.getByRole('button',{name:'Сохранить',exact:true}).click()]);
 assert.equal(await p.locator('[name="mapping[name]"]').inputValue(),'B,G,F');assert.equal(await box.isChecked(),enabled);
 const saved=JSON.parse(php('echo json_encode(App\\Models\\SourceRepository::find('+id+')["mapping_array"]);'));
 assert.equal(saved._merge_skip_empty,enabled?'1':'0');assert.equal(saved.name,'B,G,F');
}
await p.locator('[name="mapping[name]"]').fill('B,,F');await Promise.all([p.waitForNavigation(),p.getByRole('button',{name:'Сохранить',exact:true}).click()]);assert.equal(await p.locator('[name="mapping[name]"]').inputValue(),'B,,F');assert.equal(JSON.parse(php('echo json_encode(App\\Models\\SourceRepository::find('+id+')["mapping_array"]);')).name,'B,G,F');
await p.goto(base+'/admin/yml.php?tab=warehouses&wh_form=0');assert(await p.locator('input[type=checkbox][name="mapping[_merge_skip_empty]"]').isVisible());
console.log('PASS UI: merge mapping and both checkbox states survive save, invalid mapping cannot overwrite settings, warehouse checkbox visible');
}finally{await browser.close();if(id)php('App\\Models\\SourceRepository::delete('+id+');');}})().catch(error=>{console.error(error);process.exit(1)});
