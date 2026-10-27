// Run only against a development instance with Playwright installed.
// YML_TEST_PASSWORD_FILE must point to a local password file; its value is never printed.
const {chromium}=require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const fs=require('fs'),assert=require('assert/strict');
(async()=>{const b=await chromium.launch({executablePath:'/usr/bin/chromium',args:['--no-sandbox']});let page;const suffix=Date.now();const name='Unified test '+suffix;
const base=(process.env.YML_TEST_BASE_URL || 'http://127.0.0.1:8080').replace(/\/$/,'');
try{
page=await b.newPage();
await page.goto(base+'/admin/index.php?page=login');
await page.locator('[name=username]').fill(process.env.YML_TEST_USER || 'devadmin');await page.locator('[name=password]').fill(fs.readFileSync(process.env.YML_TEST_PASSWORD_FILE,'utf8').trim());await page.locator('button[type=submit]').click();
await page.goto(base+'/admin/yml.php?tab=warehouses&wh_form=new');
assert.equal(await page.locator('#warehouse-settings').count(),1);
await page.locator('[name=name]').fill(name);await page.locator('[name=code]').fill('unified-test');
await page.locator('[name=type]').selectOption('csv');await page.locator('[name="mapping[sku]"]').fill('A');await page.locator('[name="mapping[name]"]').fill('B');await page.locator('[name="mapping[stock]"]').fill('C');await page.locator('[name="mapping[price]"]').fill('D');
// Validation must not create a warehouse and must keep all entered settings.
await page.locator('#warehouse-settings button').filter({hasText:/^Сохранить$/}).click();
assert.equal(await page.locator('[name=name]').inputValue(),name);assert.equal(await page.locator('[name="mapping[sku]"]').inputValue(),'A');
assert(await page.locator('.alert--error').innerText().then(x=>x.includes('Загрузите файл')));
await page.locator('[name=price_file]').setInputFiles({name:'stock.csv',mimeType:'text/csv',buffer:Buffer.from('sku;name;stock;price\nTEST-1;Test item;3;100\n')});
await page.locator('[name=save_and_import]').click();
assert(await page.locator('body').innerText().then(x=>!x.includes('Что-то пошло не так')));
let row=page.locator('tr').filter({has:page.getByText(name,{exact:true})});
assert.equal(await row.count(),1);assert(await row.innerText().then(x=>x.includes('stock.csv') && x.includes('OK')));
await row.getByRole('link',{name:'Изменить',exact:true}).click();
assert.equal(await page.locator('[name="mapping[sku]"]').inputValue(),'A');
await page.locator('[name=save_and_preview]').click();assert(await page.getByText('Проверка маппинга: '+name,{exact:true}).isVisible());
// Keep existing file when saving without a replacement and when switching to virtual.
await page.locator('[name=kind]').selectOption('virtual');await page.locator('[name=default_stock]').fill('7');assert(!(await page.locator('.warehouse-file-settings').isVisible()));
await page.locator('#warehouse-settings button').filter({hasText:/^Сохранить$/}).click();
row=page.locator('tr').filter({has:page.getByText(name,{exact:true})});assert(await row.innerText().then(x=>x.includes('виртуальный · 7')));
await row.getByRole('link',{name:'Изменить',exact:true}).click();await page.locator('[name=kind]').selectOption('file');await page.locator('#warehouse-settings button').filter({hasText:/^Сохранить$/}).click();
row=page.locator('tr').filter({has:page.getByText(name,{exact:true})});assert(await row.innerText().then(x=>x.includes('stock.csv')));
await row.getByRole('link',{name:'Изменить',exact:true}).click();
await page.locator('[name=name]').fill(name+' unsaved');await page.locator('[name=fetch_method]').selectOption('url');await page.locator('[name=source_url]').fill('invalid');
await page.locator('#warehouse-settings button').filter({hasText:/^Сохранить$/}).click();assert(await page.locator('.alert--error').count()>0);assert.equal(await page.locator('[name=name]').inputValue(),name+' unsaved');
assert.equal(await page.locator('tr').filter({has:page.getByText(name,{exact:true})}).count(),1);
await page.locator('[name=name]').fill(name);await page.locator('[name=source_url]').fill('https://example.com/stock.csv');await page.locator('#warehouse-settings button').filter({hasText:/^Сохранить$/}).click();
row=page.locator('tr').filter({has:page.getByText(name,{exact:true})});assert(await row.innerText().then(x=>x.includes('https://example.com/stock.csv')));
await row.getByRole('link',{name:'Изменить',exact:true}).click();await page.locator('[name=fetch_method]').selectOption('upload');await page.locator('#warehouse-settings button').filter({hasText:/^Сохранить$/}).click();
row=page.locator('tr').filter({has:page.getByText(name,{exact:true})});assert(await row.innerText().then(x=>x.includes('stock.csv')));
// All shared actions remain available.
await row.getByRole('button',{name:'Отключить',exact:true}).click();row=page.locator('tr').filter({has:page.getByText(name,{exact:true})});assert(await row.getByRole('button',{name:'Включить',exact:true}).isVisible());await row.getByRole('button',{name:'Включить',exact:true}).click();
for(const tab of ['matches','settings','feed']) {await page.goto(base+'/admin/yml.php?tab='+tab);assert(!(await page.locator('body').innerText()).includes('Что-то пошло не так'));}
console.log('PASS: combined creation, validation and retained inputs, file import, mapping preview, virtual/file switching with retained file, all YML tabs');
}finally{
if(page){await page.goto(base+'/admin/yml.php');const row=page.locator('tr').filter({has:page.getByText(name,{exact:true})});if(await row.count()){page.on('dialog',d=>d.accept());await row.locator('form').filter({has:page.locator('[name=action][value=warehouse-delete]')}).locator('button').click();}}
await b.close();}
})().catch(e=>{console.error(e);process.exit(1)});
