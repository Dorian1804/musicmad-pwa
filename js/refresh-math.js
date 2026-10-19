(() => {
 const move=(start,x,y,count=1)=>{
  if(!start||count!==1||![start.x,start.y,x,y].every(Number.isFinite))return null;
  const dx=x-start.x,dy=y-start.y;if(dy<0||Math.abs(dx)>35||Math.abs(dx)>Math.max(12,dy*.7))return null;
  return {distance:Math.min(140,dy),intercept:dy>=12,ready:dy>=90,bucket:Math.min(4,Math.max(0,Math.floor(dy/25)))};
 };
 const dirty=field=>{
  if(!field||field.disabled||['hidden','submit','button','reset','image'].includes(field.type))return false;
  if(field.type==='file')return Boolean(field.files?.length);
  if(field.type==='checkbox'||field.type==='radio')return field.checked!==field.defaultChecked;
  if(field.options){
   const options=Array.from(field.options),explicit=options.some(option=>option.defaultSelected);
   const fallback=!field.multiple&&!explicit&&(field.size||0)<=1?options.findIndex(option=>!option.disabled):-1;
   return options.some((option,index)=>Boolean(option.selected)!==Boolean(option.defaultSelected||index===fallback));
  }
  return typeof field.value==='string'&&field.value!==field.defaultValue;
 };
 const api=Object.freeze({move,dirty});if(typeof module==='object'&&module.exports)module.exports=api;else window.MusicMadRefreshMath=api;
})();
