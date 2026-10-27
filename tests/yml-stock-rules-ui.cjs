// Development instance only: exercises settings persistence and restores original controls.
const {chromium}=require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const fs=require('fs'),assert=require('assert/strict');
const base=(process.env.YML_TEST_BASE_URL || 'http://127.0.0.1:8080').replace(/\/$/,'');
(async()=>{const browser=await chromium.launch({executablePath:process.env.BROWSER_PATH || '/usr/bin/chromium',args:['--no-sandbox']});let p,original;
const names=['numeric_stock_enabled','numeric_stock_threshold','numeric_stock_value','numeric_stock_lower_enabled','numeric_stock_lower_threshold','numeric_stock_lower_value'];
try {
 p=await browser.newPage();await p.goto(base+'/admin/index.php?page=login');await p.locator('[name=username]').fill(process.env.YML_TEST_USER || 'devadmin');await p.locator('[name=password]').fill(fs.readFileSync(process.env.YML_TEST_PASSWORD_FILE,'utf8').trim());await p.locator('button[type=submit]').click();await p.goto(base+'/admin/yml.php?tab=settings');
 original={};for(const name of names){const input=p.locator('[name='+name+']');original[name]=name.endsWith('enabled')?await input.isChecked():await input.inputValue();}
 await p.locator('[name=numeric_stock_enabled]').check();await p.locator('[name=numeric_stock_lower_enabled]').check();
 for(const [name,value]of Object.entries({numeric_stock_threshold:'5',numeric_stock_value:'10',numeric_stock_lower_threshold:'4',numeric_stock_lower_value:'0'}))await p.locator('[name='+name+']').fill(value);
 async function save(){await Promise.all([p.waitForNavigation(),p.getByRole('button',{name:'Сохранить настройки',exact:true}).click()]);}
 await save();await p.reload();assert(await p.locator('[name=numeric_stock_enabled]').isChecked());assert(await p.locator('[name=numeric_stock_lower_enabled]').isChecked());assert.equal(await p.locator('[name=numeric_stock_lower_threshold]').inputValue(),'4');
 await p.locator('[name=numeric_stock_lower_threshold]').fill('5');await save();assert(await p.getByText(/Диапазоны пересекаются/).isVisible());await p.reload();assert.equal(await p.locator('[name=numeric_stock_lower_threshold]').inputValue(),'4','Invalid overlap did not persist');
 await p.locator('[name=numeric_stock_threshold]').fill('');await save();assert(await p.getByText(/Порог и число замены должны быть целыми/).isVisible());await p.reload();assert.equal(await p.locator('[name=numeric_stock_threshold]').inputValue(),'5','Invalid empty threshold did not persist');
 console.log('PASS UI: inclusive upper/lower stock settings persist; overlapping and invalid thresholds are rejected');
}finally{
 if(p&&original){await p.goto(base+'/admin/yml.php?tab=settings');for(const name of names){const input=p.locator('[name='+name+']');if(name.endsWith('enabled'))await input.setChecked(original[name]);else await input.fill(original[name]);}await Promise.all([p.waitForNavigation(),p.getByRole('button',{name:'Сохранить настройки',exact:true}).click()]);}
 await browser.close();
}})().catch(e=>{console.error(e);process.exit(1)});
