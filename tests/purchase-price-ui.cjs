// Clean development environment only. Creates/removes its own supplier and code setting.
const {chromium}=require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const fs=require('fs'),path=require('path'),assert=require('assert/strict'),{execFileSync}=require('child_process');
const root=path.resolve(__dirname,'..'),base=process.env.YML_TEST_BASE_URL || 'http://127.0.0.1:8080';
const config=path.join(root,'storage/purchase-access.json');
if(fs.existsSync(config))throw new Error('Existing access setting preserved. Run on a clean development instance.');
const php=code=>execFileSync(process.env.PHP_BIN || 'php',['-r',"require 'app/bootstrap.php'; "+code],{cwd:root,encoding:'utf8'}).trim();
const limit=path.join(root,'storage/tmp/purchase-limit-'+require('crypto').createHash('sha256').update('127.0.0.1').digest('hex')+'.json');
const oldLimit=fs.existsSync(limit)?fs.readFileSync(limit):null;
(async()=>{let seed;const browser=await chromium.launch({executablePath:process.env.BROWSER_PATH || '/usr/bin/chromium',args:['--no-sandbox']});try{
seed=JSON.parse(php("$id=App\\Models\\SourceRepository::create(['name'=>'Purchase UI test','type'=>'csv','fetch_method'=>'url','source_url'=>'https://example.invalid/test.csv','mapping'=>['name'=>'B','sku'=>'A']]); $row=['sku'=>'cost-sku','name'=>'Test order item','price'=>1000,'price_text'=>'1000','promo_price'=>800,'promo_price_text'=>'800','purchase_price'=>137.25,'purchase_price_text'=>'137.25','currency'=>'RUB','stock_qty'=>5,'stock_text'=>'5','availability'=>'in_stock','image_url'=>null,'extra'=>null]; App\\Models\\ProductRepository::upsertBatch($id,[$row],0); echo json_encode(['supplier'=>$id,'product'=>App\\Support\\Db::scalar('SELECT id FROM products WHERE source_id=?',[$id])]);"));
const admin=await browser.newPage();await admin.goto(base+'/admin/index.php?page=login');await admin.locator('[name=username]').fill(process.env.YML_TEST_USER || 'devadmin');await admin.locator('[name=password]').fill(fs.readFileSync(process.env.YML_TEST_PASSWORD_FILE,'utf8').trim());await admin.locator('button[type=submit]').click();
await admin.goto(base+'/admin/index.php?page=source-form&id='+seed.supplier);await admin.locator('[name="mapping[purchase_price]"]').fill('E');await Promise.all([admin.waitForNavigation(),admin.getByRole('button',{name:'Сохранить',exact:true}).click()]);assert.equal(await admin.locator('[name="mapping[purchase_price]"]').inputValue(),'E');
async function setCode(code){await admin.goto(base+'/admin/index.php?page=settings');await admin.locator('[name=purchase_code]').fill(code);await Promise.all([admin.waitForNavigation(),admin.getByRole('button',{name:'Сохранить код доступа',exact:true}).click()]);assert(!(await admin.content()).includes(code));assert(!fs.readFileSync(config,'utf8').includes(code));}
await setCode('test-code-123');
const context=await browser.newContext({acceptDownloads:true,viewport:{width:390,height:844}});const p=await context.newPage();await p.goto(base+'/orders.php');
await p.evaluate(seed=>{localStorage.setItem('catalog_order_v1',JSON.stringify([{id:seed.product,name:'Test order item',supplier_id:seed.supplier,supplier:'Purchase UI test',qty:2}]));},seed);await p.reload();await p.locator('.order-group').evaluate(n=>n.open=true);
const token=()=>p.locator('#js-purchase-access').getAttribute('data-csrf');
async function api(action,fields={}){return context.request.post(base+'/api/order.php',{form:{action,csrf_token:await token(),...fields}});}
const items=JSON.stringify([{id:seed.product,name:'Test order item',qty:2,purchase_price:'FAKE'}]);
assert.equal((await api('prices',{items})).status(),403);
assert.equal((await context.request.post(base+'/api/order.php',{form:{action:'unlock',code:'test-code-123'}})).status(),419);
let csv=await (await api('export',{items,unlocked:'1'})).text();assert(!csv.includes('Закупочная')&&!csv.includes('137.25'));
const catalog=await (await context.request.get(base+'/api/products.php?format=json&supplier='+seed.supplier)).text();assert(!catalog.includes('purchase_price')&&!catalog.includes('137.25'));
await p.locator('.js-total-unlock').click();await p.locator('#purchase-code').fill('wrong');await p.locator('#js-purchase-form button').click();await p.getByText('Неверный код.',{exact:true}).waitFor();assert.equal(await p.locator('.order-item__purchase').count(),0);
await p.locator('#purchase-code').fill('test-code-123');await p.locator('#js-purchase-form button').click();await p.getByText('Закупочная цена: 137.25 ₽ · Сумма: 274.50 ₽',{exact:true}).waitFor();
assert.equal(await p.locator('#js-orders-purchase-total strong').textContent(),'Итого по всем позициям: 274.50 ₽');
await p.locator('.js-qty-plus').click();await p.getByText('Закупочная цена: 137.25 ₽ · Сумма: 411.75 ₽',{exact:true}).waitFor();assert.equal(await p.locator('#js-orders-purchase-total strong').textContent(),'Итого по всем позициям: 411.75 ₽');
// A second position and its quantity must contribute to the grand total.
const second=Number(php("$row=App\\Support\\Db::first('SELECT * FROM products WHERE id=?',["+Number(seed.product)+"]); unset($row['id']); $row['sku']='second-cost-sku'; $row['name']='Second item'; $row['purchase_price']=200; $row['purchase_price_text']='200'; App\\Models\\ProductRepository::upsertBatch("+Number(seed.supplier)+",[$row],0); echo App\\Support\\Db::scalar('SELECT id FROM products WHERE source_id=? AND sku=?',["+Number(seed.supplier)+",'second-cost-sku']);"));
await p.evaluate(({id,supplier})=>OrderStore.add({id,name:'Second item',supplier_id:supplier,supplier:'Purchase UI test'}),{id:second,supplier:seed.supplier});
await p.waitForFunction(()=>document.querySelector('#js-orders-purchase-total strong').textContent==='Итого по всем позициям: 611.75 ₽');
await p.evaluate(id=>OrderStore.setQty(id,2),second);await p.waitForFunction(()=>document.querySelector('#js-orders-purchase-total strong').textContent==='Итого по всем позициям: 811.75 ₽');
await p.evaluate(id=>OrderStore.remove(id),second);await p.waitForFunction(()=>document.querySelector('#js-orders-purchase-total strong').textContent==='Итого по всем позициям: 411.75 ₽');
assert(!(await p.evaluate(()=>localStorage.getItem('catalog_order_v1'))).includes('137.25'));
csv=await (await api('export',{items})).text();assert(csv.includes('Закупочная цена')&&csv.includes('137,25')&&!csv.includes('FAKE')&&csv.includes('Итого')&&csv.includes('274,50')&&csv.includes('Валюта'));
const downloadEvent=p.waitForEvent('download');await p.locator('.js-download').click();const download=await downloadEvent;const downloaded=fs.readFileSync(await download.path(),'utf8');assert(downloaded.includes('137,25')&&downloaded.includes('Закупочная цена')&&downloaded.includes('Итого')&&downloaded.includes('411,75'));
assert(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
await p.locator('#js-purchase-hide').click();await p.locator('#js-purchase-show').waitFor();assert.equal(await p.locator('.order-item__purchase').count(),0);assert(await p.locator('#js-orders-purchase-total').isVisible());assert(!(await p.locator('#js-orders-purchase-total').textContent()).includes('411.75'));assert.equal((await api('prices',{items})).status(),403);assert(!(await (await api('export',{items})).text()).includes('Закупочная'));
await api('unlock',{code:'test-code-123'});await setCode('replacement-code-456');assert.equal((await api('prices',{items})).status(),403);assert.equal((await api('unlock',{code:'test-code-123'})).status(),403);
assert.equal((await api('unlock',{code:'replacement-code-456'})).status(),200);
await admin.goto(base+'/admin/index.php?page=settings');await admin.locator('[name=disable_purchase_code]').check();await Promise.all([admin.waitForNavigation(),admin.getByRole('button',{name:'Сохранить код доступа',exact:true}).click()]);assert.equal((await api('prices',{items})).status(),403);
for(let i=0;i<5;i++)assert.equal((await api('unlock',{code:'wrong'})).status(),403);
assert.equal((await api('unlock',{code:'wrong'})).status(),429);
await context.close();
console.log('PASS UI/API: mapping, admin code hash, locked catalog/API/CSV, CSRF, unlock, real CSV download, mobile layout, hide, rotation/revocation and attempt limit');
}finally{await browser.close();if(seed)php('App\\Models\\SourceRepository::delete('+Number(seed.supplier)+');');if(fs.existsSync(config))fs.unlinkSync(config);if(oldLimit)fs.writeFileSync(limit,oldLimit);else if(fs.existsSync(limit))fs.unlinkSync(limit);}})().catch(error=>{console.error(error);process.exit(1)});
