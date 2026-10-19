/* The only persistent notification state is an opaque device/account binding. */
(() => {
 let queue=Promise.resolve();
 function db(){return new Promise((resolve,reject)=>{const request=indexedDB.open('musicmad-notifications',1);request.onupgradeneeded=()=>request.result.createObjectStore('state');request.onsuccess=()=>resolve(request.result);request.onerror=()=>reject(new Error('Notification storage unavailable'));});}
 async function operation(write,value){const connection=await db();return new Promise((resolve,reject)=>{const transaction=connection.transaction('state',write?'readwrite':'readonly');const store=transaction.objectStore('state');const request=write?(value?store.put(value,'active'):store.delete('active')):store.get('active');let result=null;request.onsuccess=()=>{result=request.result;};transaction.oncomplete=()=>{connection.close();resolve(result);};transaction.onerror=transaction.onabort=()=>{connection.close();reject(new Error('Notification storage unavailable'));};});}
 self.MusicMadPushState={write:value=>{queue=queue.catch(()=>{}).then(()=>operation(true,value));return queue;},read:()=>queue.catch(()=>{}).then(()=>operation(false))};
})();
