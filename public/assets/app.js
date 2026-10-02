// An accessible, in-page confirmation keeps each transaction in context.
const confirmation=document.createElement('dialog');
confirmation.className='confirm-dialog';
confirmation.setAttribute('aria-labelledby','confirmation-title');
confirmation.setAttribute('aria-describedby','confirmation-copy');
confirmation.innerHTML='<span class="mini-icon"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="m12 2 9 4v6c0 5-9 10-9 10S3 17 3 12V6l9-4Z"/><path d="m8 12 3 3 5-6"/></svg></span><h2 id="confirmation-title">Confirm this action</h2><p id="confirmation-copy"></p><div class="confirm-actions"><button type="button" class="btn btn-outline-dark" data-dismiss>Go back</button><button type="button" class="btn btn-primary" data-approve>Confirm</button></div>';
document.body.append(confirmation);
let pendingForm=null,pendingSubmitter=null,approvedForm=null;
document.querySelectorAll('form[data-confirm], form[data-confirm-actions]').forEach(form=>form.addEventListener('submit',event=>{
 if(approvedForm===form){approvedForm=null;return;}
 const message=event.submitter?.dataset.confirm || form.dataset.confirm;
 if(!message)return;
 event.preventDefault();pendingForm=form;pendingSubmitter=event.submitter;
 confirmation.querySelector('#confirmation-copy').textContent=message;
 confirmation.showModal();confirmation.querySelector('[data-dismiss]').focus();
}));
confirmation.querySelector('[data-dismiss]').addEventListener('click',()=>{confirmation.close();pendingForm=null;});
confirmation.addEventListener('cancel',()=>{pendingForm=null;});
confirmation.querySelector('[data-approve]').addEventListener('click',()=>{
 const form=pendingForm,submitter=pendingSubmitter;confirmation.close();pendingForm=null;
 if(form){approvedForm=form;form.requestSubmit(submitter || undefined);}
});
// Associate every visible form label with its input, including generic master forms.
document.querySelectorAll('label:not([for])').forEach((label,index)=>{
 let input=label.querySelector('input,select,textarea');
 let sibling=label.nextElementSibling;
 while(!input && sibling && !sibling.matches('label')){input=sibling.matches('input:not([type=hidden]),select,textarea')?sibling:sibling.querySelector('input:not([type=hidden]),select,textarea');sibling=sibling.nextElementSibling;}
 if(input){if(!input.id)input.id=`field-${index}`;label.htmlFor=input.id;}
});
const sidebar=document.querySelector('#sidebar'),toggle=document.querySelector('.menu-toggle');
if(sidebar&&toggle){
 const scrim=document.createElement('button');scrim.className='mobile-scrim';scrim.setAttribute('aria-label','Close navigation');document.body.append(scrim);
 const close=()=>{sidebar.classList.remove('open');scrim.classList.remove('visible');toggle.setAttribute('aria-expanded','false');};
 toggle.setAttribute('aria-controls','sidebar');toggle.setAttribute('aria-expanded','false');
 toggle.addEventListener('click',()=>{const open=sidebar.classList.contains('open');scrim.classList.toggle('visible',open);toggle.setAttribute('aria-expanded',String(open));});
 scrim.addEventListener('click',close);document.addEventListener('keydown',event=>{if(event.key==='Escape')close();});
}
const peso=cents=>new Intl.NumberFormat('en-PH',{style:'currency',currency:'PHP'}).format(cents/100);
document.querySelectorAll('[data-line-editor]').forEach(editor=>{
 const body=editor.querySelector('[data-lines]');let index=body.children.length;
 const update=()=>{let sum=0;body.querySelectorAll('[data-line]').forEach(row=>{const product=row.querySelector('[data-product]'),cost=row.querySelector('[data-cost]');const cents=cost?Math.round((Number(cost.value)||0)*100):Number(product.selectedOptions[0]?.dataset.price||0);const total=cents*(Number(row.querySelector('[data-qty]').value)||0);sum+=total;row.querySelector('[data-line-total]').textContent=peso(total);});editor.querySelector('[data-items-total]').textContent=peso(sum);};
 editor.addEventListener('input',update);editor.addEventListener('change',e=>{if(e.target.matches('[data-product]')){const cost=e.target.closest('tr').querySelector('[data-cost]');if(cost)cost.value=(Number(e.target.selectedOptions[0]?.dataset.price||0)/100).toFixed(2);}update();});
 editor.addEventListener('click',e=>{if(e.target.matches('[data-remove]')&&body.children.length>1){e.target.closest('tr').remove();update();}});
 editor.querySelector('[data-add-line]').addEventListener('click',()=>{if(body.children.length>=50)return;const row=body.firstElementChild.cloneNode(true);row.querySelectorAll('[name]').forEach(input=>{input.name=input.name.replace(/items\[\d+\]/,`items[${index}]`);input.value=input.matches('[data-qty]')?'1':'';});index++;body.append(row);update();});update();
});
const fulfill=document.querySelector('[data-fulfillment]');if(fulfill){const update=()=>{const delivery=fulfill.value==='Delivery';document.querySelectorAll('[data-delivery]').forEach(el=>el.hidden=!delivery);const address=document.querySelector('[name=delivery_address]');if(address)address.required=delivery;const total=document.querySelector('#checkout-total');if(total){const fee=delivery?Number(total.dataset.fee):0;document.querySelector('#delivery-display').textContent=peso(fee);total.textContent=peso(Number(total.dataset.subtotal)+fee);}};fulfill.addEventListener('change',update);update();}
const bill=document.querySelector('#bill-total');if(bill){const update=()=>{const cents=name=>Math.round((Number(document.querySelector(`[name=${name}]`).value)||0)*100);bill.textContent=peso(Number(bill.dataset.subtotal)-cents('discount')+cents('tax')+cents('additional')+cents('shipping'));};document.querySelectorAll('[data-bill-input]').forEach(input=>input.addEventListener('input',update));update();}
// Close the account menu without interrupting keyboard or pointer navigation.
const accountMenu=document.querySelector('.account-menu');
if(accountMenu){document.addEventListener('click',event=>{if(!accountMenu.contains(event.target))accountMenu.open=false;});document.addEventListener('keydown',event=>{if(event.key==='Escape' && accountMenu.open){accountMenu.open=false;accountMenu.querySelector('summary').focus();}});}
