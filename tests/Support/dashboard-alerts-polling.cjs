const fs = require('fs'), vm = require('vm'), assert = require('node:assert/strict');
const text = fs.readFileSync(require('node:path').resolve(__dirname, '../../src/Public/Views/admin/dashboard.php'), 'utf8');
const start = text.indexOf('<script>', text.indexOf('id="latest-alerts"')) + 8;
let script = text.slice(start, text.indexOf('</script>', start));
script = script.replace(/<\?= json_encode\(\$xmAlertOptions,[\s\S]*?\?>/, JSON.stringify({latest_enabled:true, latest_count:4, history_enabled:true, history_count:20}));
script = script.replace(/<\?= json_encode\(\$language::get\('dashboard_alert_summary_window'\)[\s\S]*?\?>/, JSON.stringify('Last 60 seconds:'));
class Element {
 constructor(){this.children=[];this.dataset={};this.style={};this.scrollTop=0;this.parentElement={scrollTop:0,scrollHeight:200};this.classList={toggle:(name,value)=>this.hidden=value};}
 append(...nodes){this.children.push(...nodes)}
 appendChild(node){this.children.push(node)}
 replaceChildren(){this.children=[];this.renders=(this.renders||0)+1}
}
const nodes = Object.fromEntries(['latest-alerts','latest-alerts-list','latest-alerts-empty','latest-alerts-count','latest-alerts-scroll','latest-alerts-summary'].map(id=>[id,new Element()]));
const options={latest_enabled:true,latest_count:4,history_enabled:true,history_count:20};
let payload={events:Array.from({length:20},(_,i)=>({id:50-i,stream:i===0?'<img src=x onerror=alert(1)>':'Stream '+i,server:'Main',label:'Stream Failed',action:'STREAM_FAILED',time:1700000000})),summary:[{action:'STREAM_FAILED',label:'Stream Failed',count:500}],options};
let next;
let timeout;
let hanging = false;
let aborted = false;
vm.runInNewContext(script, {document:{hidden:false,getElementById:id=>nodes[id],createElement:()=>new Element()},AbortController,fetch:async(url, config)=>{if (hanging) return new Promise((resolve,reject)=>config.signal.addEventListener('abort',()=>{aborted=true;reject(new Error('aborted'));}));return {ok:true,json:async()=>payload};},clearTimeout:()=>{},setTimeout:(fn,ms)=>{assert([5000,10000].includes(ms));if(ms===10000){timeout=fn;}else{next=fn;}return ms;}});
const flush=()=>new Promise(resolve=>setImmediate(resolve));
(async()=>{
 await flush();
 assert.equal(nodes['latest-alerts-list'].children.length,20);
 assert.equal(nodes['latest-alerts-scroll'].style.maxHeight,'176px');
 assert.equal(nodes['latest-alerts-count'].textContent,'20 / 20');
 assert.equal(nodes['latest-alerts-summary'].children[1].textContent,'500 Stream Failed');
 assert.equal(nodes['latest-alerts-list'].children[0].children[1].children[0].textContent,'<img src=x onerror=alert(1)> (Main)');
 const renders=nodes['latest-alerts-list'].renders;
 next();await flush();assert.equal(nodes['latest-alerts-list'].renders,renders);
 options.history_enabled=false;
 next();await flush();assert.equal(nodes['latest-alerts-list'].children.length,4);
 options.history_enabled=true;options.history_count=10;options.latest_count=2;
 next();await flush();assert.equal(nodes['latest-alerts-list'].children.length,10);assert.equal(nodes['latest-alerts-scroll'].style.maxHeight,'88px');
 options.latest_enabled=false;
 next();await flush();assert.equal(nodes['latest-alerts'].hidden,true);assert.equal(nodes['latest-alerts-list'].children.length,0);
 options.latest_enabled=true;
 next();await flush();assert.equal(nodes['latest-alerts-list'].children.length,10);
 payload={error:'unavailable'};next();await flush();assert.equal(nodes['latest-alerts-list'].children.length,10);
 payload={events:[],summary:[],options};next();await flush();assert.equal(nodes['latest-alerts-list'].children.length,0);assert.equal(nodes['latest-alerts-empty'].hidden,false);
 assert(!text.includes('id="alert-history"'));
 assert(text.includes('overflow-y: auto'));
 assert(!text.includes('localStorage'));
 hanging = true;
 next(); await flush();
 assert.equal(aborted, false);
 timeout(); await flush();
 assert.equal(aborted, true);
 hanging = false;
 payload = {events:[{id:100,stream:'Recovered',server:'Main',action:'STREAM_START',label:'Stream Started',time:1700000000}],summary:[],options};
 next(); await flush();
 assert.equal(nodes['latest-alerts-list'].children.length, 1);
 assert.equal(nodes['latest-alerts-list'].children[0].children[1].children[0].textContent, 'Recovered (Main)');
 console.log('Alert UI and timeout recovery checks passed.');
})().catch(error=>{console.error(error);process.exitCode=1});
