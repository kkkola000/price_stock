const {chromium}=require(process.env.PLAYWRIGHT_MODULE || 'playwright');
// Run against a development instance with the default YML mapping.
const fs=require('fs'),assert=require('assert/strict');
(async()=>{const browser=await chromium.launch({executablePath:'/usr/bin/chromium',args:['--no-sandbox']});try{
const p=await browser.newPage({viewport:{width:1536,height:1200}});const errors=[];p.on('pageerror',e=>errors.push(e.message));
await p.goto('http://127.0.0.1:8080/admin/index.php?page=login');await p.locator('[name=username]').fill('devadmin');await p.locator('[name=password]').fill(fs.readFileSync(process.env.YML_TEST_PASSWORD_FILE,'utf8').trim());await p.locator('button[type=submit]').click();await p.goto('http://127.0.0.1:8080/admin/yml.php?tab=settings');
assert.equal(await p.locator('[data-xml-wrapper]').count(),4);assert.equal(await p.locator('[data-xml-node] select[name^="map_ref"]').count(),7);
const stock=p.locator('[data-xml-node]').filter({has:p.locator('[name^="map_name["][value="instock"]')});assert.equal(await stock.locator('[name^="map_ref["]').inputValue(),'stock');assert.equal(await stock.locator('[name^="map_ref["] option:checked').innerText(),'Остаток с учётом соответствий');
const offer=p.locator('[data-xml-wrapper=offer]');await offer.locator('[data-xml-collapse]').click();assert(!(await stock.isVisible()));await offer.locator('[data-xml-collapse]').click();assert(await stock.isVisible());
const depths=await stock.evaluate(r=>r.style.getPropertyValue('--xml-depth'));assert(Number(depths)>3);
await p.locator('[data-xml-add=block]').click();const row=p.locator('[data-xml-node]').last();await row.locator('summary').click();await row.locator('[name^="map_repeat["]').selectOption('warehouse');await row.locator('[data-xml-remove]').click();
assert.deepEqual(errors,[]);
if(process.env.YML_TEST_SCREENSHOT) await p.locator('#xml-tree').screenshot({path:process.env.YML_TEST_SCREENSHOT});
console.log('PASS: visible envelope, indentation, labelled field choices with unchanged values, collapse/expand, new block controls, no JS errors');
}finally{await browser.close()}})().catch(e=>{console.error(e);process.exit(1)});
