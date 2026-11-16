const {chromium}=require('playwright'),fs=require('fs'),path=require('path');
const vault=JSON.parse(fs.readFileSync(process.env.SITES_PREVIEW_CI_VAULT,'utf8')),out=process.env.SITES_PREVIEW_EVIDENCE_DIR;
(async()=>{const browser=await chromium.launch({headless:true});try{
 const denied=await browser.newContext();const noauth=await denied.request.get('http://127.0.0.1:18990/onepage');if(noauth.status()!==401)throw Error('Unauthenticated preview');await denied.close();
 const context=await browser.newContext({httpCredentials:{username:vault.user,password:vault.password}}),page=await context.newPage();let count=1;
 for(const [product,route]of[['onepage','onepage'],['landing_page','landing-page']])for(const width of[1440,390]){
  await page.setViewportSize({width,height:900});const response=await page.goto('http://127.0.0.1:18990/'+route);if(response.status()!==200)throw Error('Preview unavailable');
  if(!await page.getByRole('note').getByText('DEMONSTRAÇÃO PRIVADA — sem contratação, pagamento ou aceite.').isVisible())throw Error('Purpose label absent');
  if(!await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1))throw Error('Preview overflow');
  await page.screenshot({path:path.join(out,product+'-'+width+'.png'),fullPage:true});count+=3;
 }
 console.log('PRIVATE PREVIEW BROWSER PASS: '+count+' auth/purpose/desktop/mobile checks; UI-only persisted demonstration, no Portal admission.');
}finally{await browser.close();}})().catch(()=>{console.error('Private demo preview browser failed; no credentials/HTML logged');process.exit(1);});
