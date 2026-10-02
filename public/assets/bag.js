(() => {
 const bag=document.querySelector('[data-shopping-bag]');
 if(!bag || !bag.querySelector('#bag-selection')) return;
 const checks=[...bag.querySelectorAll('[data-bag-select]')];
 const eligible=checks.filter(input=>!input.disabled);
 const all=bag.querySelector('[data-select-all]');
 const selected=()=>checks.filter(input=>input.checked && !input.disabled);
 const dirty=()=>[...bag.querySelectorAll('[data-edit-quantity]')].some(input=>input.value!==input.defaultValue);
 const update=()=>{
  const chosen=selected(),quantity=chosen.reduce((sum,input)=>sum+Number(input.dataset.quantity),0);
  const total=chosen.reduce((sum,input)=>sum+Number(input.dataset.quantity)*Number(input.dataset.price),0);
  bag.querySelector('[data-selected-count]').textContent=`${quantity} ${quantity===1?'pair':'pairs'} selected`;
  bag.querySelector('[data-selected-total]').textContent=new Intl.NumberFormat('en-PH',{style:'currency',currency:'PHP'}).format(total/100);
  all.checked=eligible.length>0 && chosen.length===eligible.length;all.indeterminate=chosen.length>0 && chosen.length<eligible.length;all.disabled=!eligible.length;
  bag.querySelector('[data-bag-checkout]').disabled=!chosen.length || dirty();
  bag.querySelector('[data-remove-selected]').disabled=!chosen.length;
  bag.querySelector('[data-selection-hint]').textContent=dirty()?'Save or cancel quantity changes before checkout.':chosen.length?'Unselected items stay in your cart.':'Select at least one item to continue.';
  checks.forEach(input=>input.closest('[data-bag-item]').classList.toggle('is-selected',input.checked && !input.disabled));
 };
 all.addEventListener('change',()=>{eligible.forEach(input=>input.checked=all.checked);update();});
 checks.forEach(input=>input.addEventListener('change',update));
 bag.querySelectorAll('[data-edit-quantity]').forEach(input=>input.addEventListener('input',update));
 bag.querySelectorAll('[data-cancel-edit]').forEach(button=>button.addEventListener('click',()=>{const editor=button.closest('[data-bag-editor]'),input=editor.querySelector('[data-edit-quantity]');input.value=input.defaultValue;editor.open=false;update();}));
 bag.querySelectorAll('[data-bag-action]').forEach(form=>form.addEventListener('submit',()=>{
  const fields=form.querySelector('[data-selection-fields]');fields.replaceChildren();selected().forEach(check=>{const input=document.createElement('input');input.type='hidden';input.name='selected[]';input.value=check.value;fields.append(input);});
 },true));
 update();
})();
