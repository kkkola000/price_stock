// Run only against a development instance; saves and restores the template text.
const {chromium}=require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const fs=require('fs'),assert=require('assert/strict');
const base=(process.env.YML_TEST_BASE_URL || 'http://127.0.0.1:8080').replace(/\/$/,'');
(async()=>{
 const browser=await chromium.launch({executablePath:process.env.BROWSER_PATH || '/usr/bin/chromium',args:['--no-sandbox']});
 let original=null,page;
 try {
  page=await browser.newPage({viewport:{width:1440,height:1100}});const errors=[];page.on('pageerror',e=>errors.push(e.message));
  await page.goto(base+'/admin/index.php?page=login');await page.locator('[name=username]').fill(process.env.YML_TEST_USER || 'devadmin');await page.locator('[name=password]').fill(fs.readFileSync(process.env.YML_TEST_PASSWORD_FILE,'utf8').trim());await page.locator('button[type=submit]').click();
  async function submit(button){await Promise.all([page.waitForNavigation({waitUntil:'load'}),button.click()]);}
  await page.goto(base+'/admin/yml.php?tab=settings');const editor=page.locator('#xml-template');original=await editor.inputValue();
  assert(original.includes('{% для каждого товара %}'));assert(await page.locator('#template-variables').isVisible());
  const changed='<!-- UI template regression -->\n'+original;
  await editor.fill(changed);await submit(page.getByRole('button',{name:'Проверить шаблон',exact:true}).first());assert(await page.getByText(/Шаблон корректен/).first().isVisible());assert.equal(await editor.inputValue(),changed);
  await page.reload();assert.equal(await editor.inputValue(),original,'Checking must not save');
  await editor.fill(changed);await Promise.all([page.waitForNavigation({waitUntil:'load'}),page.getByRole('button',{name:'Сохранить настройки',exact:true}).click()]);await page.reload();assert.equal(await editor.inputValue(),changed);
  await editor.fill(changed.replace(/\{\{[^{}]+\}\}/,'{{unknown_variable}}'));await Promise.all([page.waitForNavigation({waitUntil:'load'}),page.getByRole('button',{name:'Сохранить настройки',exact:true}).click()]);assert(await page.getByText(/неизвестная переменная/).isVisible());await page.reload();assert.equal(await editor.inputValue(),changed,'Invalid template must not overwrite saved settings');
  await page.locator('#template-add-variable').click();let row=page.locator('[data-template-variable]').last();await row.locator('[name^="var_name["]').fill('тест');await row.locator('[name^="var_ref["]').fill('A&B');
  await editor.evaluate(el=>{el.focus();el.setSelectionRange(0,0);el.dispatchEvent(new Event('select'));});await row.locator('[data-template-insert]').click();assert((await editor.inputValue()).startsWith('{{тест}}'));await row.locator('[data-template-remove]').click();
  const addStock=page.locator('#template-add-stock'), before=await page.locator('[name^="stock_pattern["]').count();await addStock.click();assert.equal(await page.locator('[name^="stock_pattern["]').count(),before+1);await page.locator('[data-remove-stock]').last().click();
  await editor.fill(changed);await editor.evaluate(el=>{el.focus();el.setSelectionRange(0,0);el.dispatchEvent(new Event('select'));});await page.locator('[data-template-loop=warehouse]').click();assert((await editor.inputValue()).startsWith('{% для каждого склада %}'));
  assert.deepEqual(errors,[]);await editor.fill(original);
  if(process.env.YML_TEST_SCREENSHOT)await page.screenshot({path:process.env.YML_TEST_SCREENSHOT,fullPage:true});
  console.log('PASS UI: template editor, variables, cursor insertion, loops, stock rows, dry check, save/reload, rejected invalid XML, no JS errors');
 }finally {
  if(page&&original!==null){await page.goto(base+'/admin/yml.php?tab=settings');await page.locator('#xml-template').fill(original);await Promise.all([page.waitForNavigation({waitUntil:'load'}),page.getByRole('button',{name:'Сохранить настройки',exact:true}).click()]);}
  await browser.close();
 }
})().catch(e=>{console.error(e);process.exit(1)});
