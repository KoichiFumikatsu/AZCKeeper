const fs=require('fs'),path=require('path');
const root=path.resolve(__dirname,'..'),out=path.join(root,'panel-v4');
const M=require('./panel-v4-model.js'),V=require('./panel-v4-view.js');
fs.mkdirSync(out,{recursive:true});
const stylesheet=fs.readFileSync(path.join(root,'neomorfismo-antislop/styles.css'),'utf8')+'\n'+['panel-v4.css','panel-v4-insights.css','panel-v4-placeholders.css'].map(f=>fs.readFileSync(path.join(__dirname,f),'utf8')).join('\n');
fs.writeFileSync(path.join(out,'styles.css'),stylesheet);
fs.writeFileSync(path.join(out,'app.js'),['panel-v4-model.js','panel-v4-insights.js','panel-v4-placeholders.js','panel-v4-view.js','panel-v4-runtime.js'].map(f=>fs.readFileSync(path.join(__dirname,f),'utf8')).join('\n'));
for(const page of Object.keys(V.titles)){
  const session=['roles','empresas'].includes(page)?'super':'admin';
  fs.writeFileSync(path.join(out,page+'.html'),`<!doctype html>\n<html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="color-scheme" content="light"><title>${V.titles[page]} · AZC Keeper v4</title><link rel="icon" href="../assets/favicon.ico"><link rel="stylesheet" href="styles.css"><script src="app.js" defer></script></head><body data-page="${page}">${V.render(M.fresh(),page,session,'azc')}<noscript><p>Activa JavaScript para usar los filtros y las confirmaciones de esta demostración. La navegación y el contenido inicial siguen disponibles.</p></noscript></body></html>\n`);
}
console.log('panel-v4: '+Object.keys(V.titles).length+' HTML estáticos, styles.css y app.js generados.');
