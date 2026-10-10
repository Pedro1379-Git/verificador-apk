/* ===== Dock Audit auditado (PDF, blanco/negro/grises, carta horizontal) ===== */
(function(){
const TXT={DUPLICADA:'Etiqueta duplicada (ya escaneada)',NO_PERTENECE:'Número de parte que no pertenece al pallet',EXCESO:'Etiqueta sobrante (excede lo esperado)',PIEZAS_DISTINTAS:'Cantidad de piezas distinta a la ORDER'};
async function datos(id){
  const g=async(a,b)=>{const r=await fetch('api.php?a='+a,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(b)});const j=await r.json();if(!r.ok)throw new Error(j.error||r.status);return j};
  const [st,au]=await Promise.all([g('esc_estado',{id}),g('auditoria',{id})]);return {st,au}}
function armar({st,au}){
  const e=st.embarque,P=st.pallets,inc=[];
  const suf=o=>o.slice(8);
  const porPallet={};P.forEach(p=>porPallet[p.orden]=[]);
  const add=(o,detalle,accion,pal)=>{const n=inc.length+1;inc.push({n,pallet:suf(o),detalle,accion,cod:pal||''});(porPallet[o]=porPallet[o]||[]).push(n)};
  au.saltos.forEach(s=>{const p=P.find(x=>x.orden===s.orden);add(s.orden,'Pallet omitido: '+s.razon,p&&p.estado==='completo'?'Retomado y completado después':'Pendiente, sin completar')});
  au.rechazos.forEach(r=>{const det=(r.resultado==='PIEZAS_DISTINTAS'&&r.aviso?r.aviso:TXT[r.resultado]||r.resultado)+(r.n>1?' (x'+r.n+')':'');add(r.orden,det,'Etiqueta rechazada; no se contabilizó',r.codigo||'')});
  P.forEach(p=>{if(p.estado!=='completo'&&p.escaneado<p.esperado)add(p.orden,`Faltan ${p.esperado-p.escaneado} de ${p.esperado} etiquetas`,'Sin resolver')});
  return {e,P,inc,porPallet,au}}
function dibujar(D){
  const {e,P,inc,porPallet,au}=D,{jsPDF}=window.jspdf,N=P.length;
  const d=new jsPDF({orientation:'landscape',unit:'mm',format:'letter'});
  const g=(v)=>{d.setFillColor(v,v,v)},gl=v=>{d.setDrawColor(v,v,v)},gt=v=>{d.setTextColor(v,v,v)};
  const R=(x,y,w,h,f,s,lw)=>{if(f!=null){g(f)}if(s!=null){gl(s);d.setLineWidth(lw||.25)}d.rect(x,y,w,h,f!=null&&s!=null?'FD':f!=null?'F':'S')};
  const T=(t,x,y,o={})=>{t=String(t);let sz=o.size||8;d.setFont('helvetica',o.bold?'bold':'normal');d.setFontSize(sz);
    if(o.maxw){while(sz>4&&d.getTextWidth(t)>o.maxw){sz-=.3;d.setFontSize(sz)}}
    gt(o.color==null?0:o.color);d.text(t,x,y,{align:o.align||'left',baseline:o.base||'alphabetic'})};
  const hatch=(x,y,w,h)=>{R(x,y,w,h,255,89,.2);gl(190);d.setLineWidth(.2);const st=1.5;
    for(let k=-h;k<w;k+=st){let x1=x+k,y1=y+h,x2=x+k+h,y2=y;if(x1<x){y1-=(x-x1);x1=x}if(x2>x+w){y2+=(x2-(x+w));x2=x+w}if(x1<x2)d.line(x1,y1,x2,y2)}
    gl(89);d.setLineWidth(.25);d.rect(x,y,w,h,'S')};
  const tot=(cod,fn)=>cod.reduce((a,c)=>a+P.reduce((b,p)=>{const l=p.lineas.find(z=>z.codigo===c);return b+(l?l[fn]:0)},0),0);
  const cs=[...new Set(P.flatMap(p=>p.lineas.map(l=>l.codigo)))].sort(),inf={};P.forEach(p=>p.lineas.forEach(l=>inf[l.codigo]=l));
  const csC=cs.filter(c=>!inf[c].es_rack),csR=cs.filter(c=>inf[c].es_rack);
  const eC=tot(csC,'esperado'),eR=tot(csR,'esperado'),xC=tot(csC,'escaneado'),xR=tot(csR,'escaneado');
  const X0=7,W=265.4;let y=7;
  T('DOCK AUDIT · Preparación de embarque',X0,y+3,{size:9,bold:true});
  T('AUDITADO POR ESCANEO · '+e.id,X0+W,y+3,{size:6.5,color:64,align:'right'});
  y=11.5;
  // ---- banner
  const bw=[52,34,66,20,38,29,26].map(v=>v*W/265),bh=24;let bx=X0;
  const cell=(i,f)=>{const x=bx;R(x,y,bw[i],bh,f,0,.3);bx+=bw[i];return x};
  let x=cell(0,0);T('RUTA',x+2.5,y+4,{size:6.5,bold:true,color:255});T(e.ruta,x+2.5,y+18,{size:34,bold:true,color:255,maxw:bw[0]-5});
  x=cell(1,122);T('DOCK',x+2.5,y+4,{size:6.5,bold:true,color:255});T(e.dock,x+2.5,y+16,{size:20,bold:true,color:255,maxw:bw[1]-5});
  const fs=e.salida.slice(8,10)+'/'+e.salida.slice(5,7)+'/'+e.salida.slice(0,4);
  const dsem=['DOMINGO','LUNES','MARTES','MIÉRCOLES','JUEVES','VIERNES','SÁBADO'][new Date(+e.salida.slice(0,4),+e.salida.slice(5,7)-1,+e.salida.slice(8,10)).getDay()];
  x=cell(2,0);T('SALIDA · '+dsem,x+2.5,y+3.8,{size:7,bold:true,color:215});T(fs,x+2.5,y+11.6,{size:22,bold:true,color:255});T(e.salida.slice(11,16),x+2.5,y+21.6,{size:26,bold:true,color:255});
  x=cell(3,248);T('PALLETS',x+2.5,y+4,{size:6.5,bold:true});T(String(N),x+2.5,y+18,{size:32,bold:true});
  x=cell(4,248);T('CANTIDAD ESPERADA',x+2.5,y+4,{size:6.5,bold:true});
  const q=[eC?eC+' CAJAS':'',eR?eR+' RACKS':''].filter(Boolean);q.forEach((s,i)=>T(s,x+2.5,y+(q.length>1?12+i*7.5:15),{size:q.length>1?14:18,bold:true}));
  const fa=au.fin?au.fin.slice(8,10)+'/'+au.fin.slice(5,7)+'/'+au.fin.slice(0,4):'';
  x=cell(5,248);T('FECHA  '+(fa||'___/___/______'),x+2.2,y+5,{size:7,bold:true,maxw:bw[5]-4});T('TURNO ______',x+2.2,y+11,{size:7,bold:true});
  T('Inicio '+(au.inicio?au.inicio.slice(11,16):'--:--'),x+2.2,y+17,{size:6.5,color:64});T('Fin '+(au.fin?au.fin.slice(11,16):'--:--'),x+2.2,y+21,{size:6.5,color:64});
  x=cell(6,255);
  const qrm=qrcode(0,'M');qrm.addData('DA'+e.id);qrm.make();const n=qrm.getModuleCount(),side=bw[6]-3,k=side/n;g(0);
  for(let i=0;i<n;i++)for(let j=0;j<n;j++)if(qrm.isDark(i,j))d.rect(x+1.5+j*k,y+(bh-side)/2+i*k,k+.02,k+.02,'F');
  y+=bh+2;
  // ---- matriz
  const wC=[15,25,13],wT=[14,14,17],pw=(W-wC.reduce((a,b)=>a+b)-wT.reduce((a,b)=>a+b))/N;
  const colX=[X0];[...wC,...Array(N).fill(pw),...wT].forEach(w=>colX.push(colX[colX.length-1]+w));
  const px=i=>colX[3+i],tx=colX[3+N];
  const grp=[];P.forEach(p=>{const gp=grp[grp.length-1];if(gp&&gp.s===p.serie)gp.n++;else grp.push({s:p.serie,n:1})});
  const firstIdx=new Set();{let c=0;grp.forEach(gp=>{firstIdx.add(c);c+=gp.n})}
  const H0=5,H1=7,H_M=4.2,H2=7,H3=4.5,hTot=6.3,hInc=7.5;
  const nTotRows=(csC.length?2:0)+(csR.length?2:0);
  const avail=215.9-7-(y)-(H0+H1+H_M+H2+H3)-nTotRows*hTot-hInc-54;
  const rh=Math.max(5.2,Math.min(9.5,avail/Math.max(1,cs.length)));
  const lw=.25;
  const hd=(x,y,w,h,txt,o={})=>{R(x,y,w,h,o.f==null?64:o.f,89,lw);T(txt,x+w/2,y+h/2,{size:o.size||7,bold:o.bold!==false,color:o.c==null?255:o.c,align:'center',base:'middle',maxw:w-1})};
  // r0
  hd(X0,y,colX[3]-X0,H0,'Serie de la orden (8 dígitos)',{size:6.5});
  {let c=0;grp.forEach(gp=>{hd(px(c),y,pw*gp.n,H0,gp.s,{f:102,size:8.5});c+=gp.n})}
  hd(tx,y,W+X0-tx,H0,'TOTAL ESPERADO',{size:6.5});y+=H0;
  hd(X0,y,colX[3]-X0,H1,'Pallet = últimos 2 dígitos de la orden',{size:6.5});
  P.forEach((p,i)=>hd(px(i),y,pw,H1,p.sufijo,{size:N>12?11:N>8?12:N>6?14:17}));
  hd(tx,y,W+X0-tx,H1,csC.length&&csR.length?'(cajas / racks)':csR.length?'(racks)':'(cajas)',{size:6.5});y+=H1;
  hd(X0,y,colX[3]-X0,H_M,'MROS del manifiesto',{f:248,c:64,size:6.3});
  P.forEach((p,i)=>{R(px(i),y,pw,H_M,248,89,lw);T(p.mros||'',px(i)+pw/2,y+H_M/2,{size:6.3,bold:true,align:'center',base:'middle',maxw:pw-1})});R(tx,y,W+X0-tx,H_M,248,89,lw);y+=H_M;
  hd(X0,y,colX[3]-X0,H2,'Cód. de paletizado · skids del manifiesto',{f:248,c:64,size:6.3});
  P.forEach((p,i)=>{R(px(i),y,pw,H2,248,89,lw);T(p.palcode,px(i)+pw/2,y+3,{size:6.3,bold:true,align:'center',base:'middle',maxw:pw-1});T(p.skids>1?p.skids+' skids':'1 skid',px(i)+pw/2,y+5.6,{size:5.6,color:64,align:'center',base:'middle'})});
  R(tx,y,W+X0-tx,H2,248,89,lw);y+=H2;
  ['Código','N.º de parte','Pzas/caja'].forEach((s,i)=>hd(colX[i],y,wC[i],H3,s,{f:248,c:64,size:6.3}));
  P.forEach((p,i)=>hd(px(i),y,pw,H3,(pw<16?'Cant.':'Esperado / contado'),{f:122,size:4.8,bold:false}));
  ['Esperado','Contado','Diferencia'].forEach((s,i)=>hd(tx+(i?wT.slice(0,i).reduce((a,b)=>a+b):0),y,wT[i],H3,s,{f:248,c:64,size:6.3}));y+=H3;
  const sepL=(i)=>{if(i>0&&firstIdx.has(i)){gl(0);d.setLineWidth(.7);d.line(px(i),y0m,px(i),y1m)}};
  const y0m=y;
  const cod=c=>{const L=inf[c],R_=!!L.es_rack,top=y;
    R(colX[0],y,wC[0],rh,248,89,lw);T(c,colX[0]+1.5,y+rh/2+(R_?-.8:1.2),{size:10,bold:true,maxw:wC[0]-2});
    if(R_){R(colX[0]+1.5,y+rh-3.6,9,2.8,0);T('RACKS',colX[0]+6,y+rh-1.7,{size:4.6,bold:true,color:255,align:'center'})}
    R(colX[1],y,wC[1],rh,248,89,lw);T(L.parte,colX[1]+wC[1]/2,y+rh/2,{size:7.5,align:'center',base:'middle',maxw:wC[1]-1.5});
    R(colX[2],y,wC[2],rh,248,89,lw);T(String(L.pzas||''),colX[2]+wC[2]/2,y+rh/2-(R_?.6:0),{size:7.5,align:'center',base:'middle'});if(R_)T('pzas/rack',colX[2]+wC[2]/2,y+rh/2+2.4,{size:4.8,align:'center',base:'middle',color:64});
    P.forEach((p,i)=>{const l=p.lineas.find(z=>z.codigo===c),cx=px(i);
      if(!l){hatch(cx,y,pw,rh);return}
      const dif=l.escaneado!==l.esperado;R(cx,y,pw,rh,R_?244:255,89,lw);
      const est=rh<9.2,txt=(dif?'cont. ':'')+l.escaneado+(dif?' !':' OK');
      if(pw<16){T(String(l.esperado),cx+pw/2,y+rh/2,{size:10.5,bold:true,align:'center',base:'middle'});if(dif)T('c:'+l.escaneado,cx+pw-.7,y+2.3,{size:4.8,bold:true,align:'right',base:'middle'})}
      else if(est){T(String(l.esperado),cx+pw/2-(pw>14?1.5:0),y+rh/2,{size:10.5,bold:true,align:'center',base:'middle'});T(txt,cx+pw-.8,y+rh/2,{size:4.8,bold:dif,align:'right',base:'middle',color:dif?0:90,maxw:pw/2-1})}
      else{T(String(l.esperado),cx+pw/2,y+rh*.42,{size:13,bold:true,align:'center',base:'middle'});T(txt,cx+pw/2,y+rh-1.2,{size:5.4,bold:dif,align:'center',color:dif?0:80})}
      if(dif){gl(0);d.setLineWidth(.7);d.rect(cx+.35,y+.35,pw-.7,rh-.7,'S')}});
    const te=tot([c],'esperado'),tc=tot([c],'escaneado'),df=tc-te;
    R(tx,y,wT[0],rh,248,89,lw);T(String(te),tx+wT[0]/2,y+rh/2,{size:11,bold:true,align:'center',base:'middle'});
    R(tx+wT[0],y,wT[1],rh,255,89,lw);T(String(tc),tx+wT[0]+wT[1]/2,y+rh/2,{size:11,bold:true,align:'center',base:'middle'});
    R(tx+wT[0]+wT[1],y,wT[2],rh,df?230:255,89,lw);T(df?(df>0?'+':'')+df:'0',tx+wT[0]+wT[1]+wT[2]/2,y+rh/2,{size:10,bold:!!df,align:'center',base:'middle'});
    y+=rh};
  cs.forEach(cod);
  const tr=(cods,nom,color)=>{const ex=nom==='RACKS'?eR:eC,co=nom==='RACKS'?xR:xC,fnT=(p,fn)=>p.lineas.filter(l=>cods.includes(l.codigo)).reduce((a,l)=>a+l[fn],0);
    R(colX[0],y,colX[3]-X0,hTot,nom==='RACKS'?0:64,89,lw);T(nom+' ESPERADOS',colX[0]+1.5,y+hTot/2,{size:7.5,bold:true,color:255,base:'middle'});
    P.forEach((p,i)=>{const v=fnT(p,'esperado');if(!v){hatch(px(i),y,pw,hTot);return}R(px(i),y,pw,hTot,nom==='RACKS'?230:255,89,lw);T(String(v),px(i)+pw/2,y+hTot/2,{size:11,bold:true,align:'center',base:'middle'})});
    R(tx,y,W+X0-tx,hTot,nom==='RACKS'?230:255,89,lw);T(String(ex),tx+(W+X0-tx)/2,y+hTot/2,{size:12,bold:true,align:'center',base:'middle'});y+=hTot;
    R(colX[0],y,colX[3]-X0,hTot,nom==='RACKS'?0:64,89,lw);T(nom+' CONTADOS (escaneados)',colX[0]+1.5,y+hTot/2,{size:7.5,bold:true,color:255,base:'middle'});
    P.forEach((p,i)=>{const v=fnT(p,'esperado');if(!v){hatch(px(i),y,pw,hTot);return}const c=fnT(p,'escaneado');R(px(i),y,pw,hTot,c!==v?230:255,89,lw);T(String(c),px(i)+pw/2,y+hTot/2,{size:11,bold:true,align:'center',base:'middle'});if(c!==v){gl(0);d.setLineWidth(.7);d.rect(px(i)+.35,y+.35,pw-.7,hTot-.7,'S')}});
    R(tx,y,W+X0-tx,hTot,co!==ex?230:255,89,lw);T(String(co)+(co===ex?'  OK':'  (diferencia: '+(co-ex)+')'),tx+(W+X0-tx)/2,y+hTot/2,{size:11,bold:true,align:'center',base:'middle'});y+=hTot};
  if(csC.length)tr(csC,'CAJAS');if(csR.length)tr(csR,'RACKS');
  R(colX[0],y,colX[3]-X0,hInc,64,89,lw);T('INCIDENCIA N.º',colX[0]+1.5,y+hInc/2,{size:7.5,bold:true,color:255,base:'middle'});
  P.forEach((p,i)=>{R(px(i),y,pw,hInc,255,89,lw);const l=(porPallet[p.orden]||[]).join(', ');T(l,px(i)+pw/2,y+hInc/2,{size:l.length>7?7:9,bold:true,align:'center',base:'middle',maxw:pw-1})});
  R(tx,y,W+X0-tx,hInc,255,89,lw);y+=hInc;
  const y1m=y;{let c=0;grp.forEach((gp,gi)=>{if(gi>0){gl(0);d.setLineWidth(.7);d.line(px(c),y0m-H0-H1-H_M-H2-H3,px(c),y1m)}c+=gp.n})}
  // ---- nota
  y+=2.2;const nota='Cada columna es un pallet y corresponde a una orden (se identifica por sus últimos 2 dígitos). Los skids del manifiesto (001, 002…) y sus copias A y B pertenecen al mismo pallet. Una casilla rayada indica que esa parte no va en ese pallet. '+(csR.length?'El fondo gris y la etiqueta RACKS indican que esas partes se envían en racks, no en cajas. ':'')+''+(N>=7?'Cada casilla muestra lo esperado; si lo escaneado difiere, la casilla lleva un recuadro grueso y el número contado (c:) en la esquina.':'Cada casilla muestra lo esperado y lo contado por escaneo (OK: coincide; «!»: hay diferencia y se marca con recuadro grueso).');
  d.setFont('helvetica','normal');d.setFontSize(6.6);gt(38);const nl=d.splitTextToSize(nota,W);d.text(nl,X0,y+2.2);y+=nl.length*2.8+1.5;
  // ---- bloque inferior
  const lh=5.8,LW=100,CW=W-LW-1.5,cx0=X0+LW+1.5;
  const comp=P.filter(p=>p.estado==='completo').length,rows=[
    [`Pallets completos (escaneados): ${comp} de ${N}`+(P.some(p=>p.estado==='saltado')?`   Saltados: ${P.filter(p=>p.estado==='saltado').length}`:'')],
    [`${csC.length?`Cajas contadas: ${xC} de ${eC}   `:''}${csR.length?`Racks contados: ${xR} de ${eR}`:''}`],
    ['Ruta y dock coinciden con la hoja: [X] Sí (QR escaneado)   Transporte / placas: ________'],
    ['Condición: [  ] Flejado y sellos firmes   [  ] Manifiesto visible por ambos lados   [  ] Sin material de otra ruta'],
    [`Escaneos aceptados: ${au.resumen.OK||0}   Rechazados: ${(au.resumen.DUPLICADA||0)+(au.resumen.NO_PERTENECE||0)+(au.resumen.EXCESO||0)+(au.resumen.PIEZAS_DISTINTAS||0)}   Anulados: ${au.resumen.ANULADA||0}`]];
  R(X0,y,LW,lh,64,89,lw);T('CONTROLES DE RUTA',X0+2,y+lh/2,{size:7,bold:true,color:255,base:'middle'});
  rows.forEach((r,i)=>{R(X0,y+lh*(i+1),LW,lh,255,89,lw);T(r[0],X0+2,y+lh*(i+1)+lh/2,{size:6.6,base:'middle',maxw:LW-3})});
  const cwid=[8,38,CW-8-38-48,48];let cxx=cx0;
  ['N.º','Pallet (orden) / código','Qué se detectó','Acción tomada y responsable'].forEach((s,i)=>{R(cxx,y,cwid[i],lh,64,89,lw);T(s,cxx+2,y+lh/2,{size:7,bold:true,color:255,base:'middle'});cxx+=cwid[i]});
  const maxR=5;
  for(let r=0;r<maxR;r++){const it=inc[r];cxx=cx0;
    const last=r===maxR-1&&inc.length>maxR;
    const vals=it?(last?['…','',`Y ${inc.length-maxR+1} incidencias más (ver la hoja de detalle)`,'']:[String(it.n),it.pallet+(it.cod?' / '+it.cod:''),it.detalle,it.accion]):[String(r+1),'','',''];
    cwid.forEach((w,i)=>{R(cxx,y+lh*(r+1),w,lh,255,89,lw);T(vals[i],cxx+(i?1.5:w/2),y+lh*(r+1)+lh/2,{size:6.5,align:i?'left':'center',base:'middle',maxw:w-2.5});cxx+=w})}
  // ---- firmas
  const sy=215.9-7-3;[0,1,2].forEach(i=>{const w=(W-20)/3,sx=X0+i*(w+10);gl(0);d.setLineWidth(.3);d.line(sx,sy-3,sx+w,sy-3);T(['Preparador (nombre y firma)','Verificador (nombre y firma)','Líder (nombre y firma)'][i],sx+w/2,sy,{size:6.5,color:64,align:'center'})});
  // ---- hoja de detalle si hay muchas incidencias
  if(inc.length>maxR){d.addPage('letter','landscape');let yy=14;T('DOCK AUDIT · Detalle de incidencias · '+e.ruta+' '+e.dock+' · '+fs+' '+e.salida.slice(11,16),X0,yy,{size:11,bold:true});yy+=6;
    const w4=[10,45,W-10-45-60,60];let xx=X0;['N.º','Pallet (orden) / código','Qué se detectó','Acción tomada'].forEach((s,i)=>{R(xx,yy,w4[i],6,64,89,lw);T(s,xx+2,yy+3,{size:7.5,bold:true,color:255,base:'middle'});xx+=w4[i]});yy+=6;
    inc.forEach(it=>{if(yy>200){d.addPage('letter','landscape');yy=14}xx=X0;[String(it.n),it.pallet+(it.cod?' / '+it.cod:''),it.detalle,it.accion].forEach((s,i)=>{R(xx,yy,w4[i],6,255,89,lw);T(s,xx+2,yy+3,{size:7.5,base:'middle',maxw:w4[i]-3});xx+=w4[i]});yy+=6})}
  // ---- marca de agua de estatus (gris claro, en todas las hojas)
  const completo=P.length>0&&P.every(p=>p.estado==='completo'),wm=completo?'COMPLETADO':'INCOMPLETO',ang=28,rad=ang*Math.PI/180;
  for(let pg=1;pg<=d.getNumberOfPages();pg++){d.setPage(pg);d.saveGraphicsState();d.setGState(new d.GState({opacity:completo?.09:.30,'stroke-opacity':completo?.09:.45}));if(!completo){d.setDrawColor(0);d.setLineWidth(1.4)}
    d.setFont('helvetica','bold');d.setFontSize(96);gt(0);const w=d.getTextWidth(wm),cx=139.7,cy=107.9,h=96*.3528;
    d.text(wm,cx-(w/2)*Math.cos(rad)+(h/3)*Math.sin(rad),cy+(w/2)*Math.sin(rad)+(h/3)*Math.cos(rad),completo?{angle:ang}:{angle:ang,renderingMode:'fillThenStroke'});d.restoreGraphicsState()}
  return d}
window.descargarDockAudit=async function(id){
  const D=armar(await datos(id));const doc=dibujar(D),e=D.e;
  doc.save(`DockAudit_${e.ruta}_${e.dock}_${e.salida.slice(0,10).replace(/-/g,'')}_${e.salida.slice(11,16).replace(':','')}_auditado.pdf`)};
window.__dockAuditDoc=async function(id){return dibujar(armar(await datos(id)))};
})();
