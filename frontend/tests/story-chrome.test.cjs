const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const {JSDOM}=require('jsdom');

test('montaje, callbacks y restauración del HTML original',()=>{
  const dom=new JSDOM('<div id="host"><span>Controles originales</span></div>',{runScripts:'outside-only',pretendToBeVisual:true,url:'http://localhost/'});
  try{
    const {window}=dom,host=window.document.getElementById('host');
    window.eval(fs.readFileSync(path.join(__dirname,'../dist/story-chrome.min.js'),'utf8'));
    let closed=0;
    const instance=window.MusicMadStoryChrome.mount(host,{close:()=>closed++,previous(){},next(){},control(){}},{});
    assert.equal(host.dataset.reactMounted,'1');
    assert.ok(host.querySelector('progress'));
    host.querySelector('[data-story-close]').click();assert.equal(closed,1);
    instance.unmount();assert.equal(host.textContent,'Controles originales');
    assert.equal(host.dataset.reactMounted,undefined);
    instance.unmount(); // Teardown is safe to call repeatedly.
  }finally{dom.window.close();}
});
