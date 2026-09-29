'use strict';
const fs=require('node:fs'),path=require('node:path');
const App=require('./app.js');
for(const p of App.data.pages){
 const html='<!doctype html>\n<html lang="es">\n<head>\n<meta charset="utf-8">\n<meta name="viewport" content="width=device-width, initial-scale=1">\n<meta name="color-scheme" content="light">\n<title>'+p.title+' · AZCKeeper v4</title>\n<link rel="icon" href="favicon.ico">\n<link rel="stylesheet" href="styles.css">\n<script src="data.js" defer></script>\n<script src="app.js" defer></script>\n</head>\n<body data-page="'+p.id+'">\n'+App.render(p.id)+'\n</body>\n</html>\n';
 fs.writeFileSync(path.join(__dirname,p.id+'.html'),html);
}
console.log(App.data.pages.length+' páginas generadas; escritura limitada a '+__dirname);
