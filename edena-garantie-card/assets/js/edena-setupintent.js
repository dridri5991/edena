(function () {
  window.__EDENA__ = window.__EDENA__ || { stripe:null, elements:null, card:null, cardMountedTo:null, btn:null, handler:null };
  var S = window.__EDENA__;

  function hasGuaranteeBlock(){ return !!document.getElementById('edena-card-element'); }
  function whenStripeReady(cb, tries){ tries=(typeof tries==='number')?tries:100; if(typeof window.Stripe==='function') return cb(); if(tries<=0){console.error('[EDENA] Stripe.js non présent'); return;} setTimeout(function(){whenStripeReady(cb,tries-1);},100); }
  function setError(msg){ var el=document.getElementById('edena-card-errors'); if(el) el.textContent=msg||''; }

  async function createSetupIntent(){
    var fd=new FormData();
    fd.append('action','edena_si_create');
    fd.append('nonce',(window.EDENA_STRIPE_OPTS||{}).nonce||'');
    // On envoie aussi le name/email si présent (améliore la création côté serveur)
    var f=document.querySelector('form.checkout');
    if(f){
      var fn=f.querySelector('#billing_first_name'); var ln=f.querySelector('#billing_last_name'); var em=f.querySelector('#billing_email');
      if(fn) fd.append('billing_first_name', fn.value||'');
      if(ln) fd.append('billing_last_name', ln.value||'');
      if(em) fd.append('billing_email', em.value||'');
    }
    var r=await fetch((window.EDENA_STRIPE_OPTS||{}).ajaxUrl,{method:'POST',credentials:'same-origin',body:fd});
    var j=await r.json();
    if(!j||!j.success||!j.data||!j.data.client_secret){ throw new Error((j&&j.data&&j.data.message)||'Création du SetupIntent échouée'); }
    return j.data;
  }
  async function attachPaymentMethod(pm, customer){
    var fd=new FormData();
    fd.append('action','edena_si_attach');
    fd.append('nonce',(window.EDENA_STRIPE_OPTS||{}).nonce||'');
    fd.append('payment_method',pm);
    fd.append('customer',customer);
    var r=await fetch((window.EDENA_STRIPE_OPTS||{}).ajaxUrl,{method:'POST',credentials:'same-origin',body:fd});
    var j=await r.json();
    if(!j||!j.success) throw new Error((j&&j.data&&j.data.message)||'Attachement échoué');
    return true;
  }
  function bindPlaceOrder(){
    var btn=document.getElementById('place_order');
    if(!btn){ setTimeout(bindPlaceOrder,200); return; }
    if(S.btn&&S.handler){ try{ S.btn.removeEventListener('click',S.handler,{capture:true}); }catch(e){} }
    S.btn=btn; S.handler=handleSubmit; btn.addEventListener('click',S.handler,{capture:true});
  }
  async function handleSubmit(ev){
    if(window.__EDENA_SI_DONE__) return;
    ev.preventDefault(); setError('');
    if(!S.card){ setError('Le champ carte n’est pas prêt.'); return; }
    try{
      var data=await createSetupIntent();
      var name=((document.getElementById('billing_first_name')||{}).value||'')+' '+((document.getElementById('billing_last_name')||{}).value||'');
      var email=(document.getElementById('billing_email')||{}).value||'';
      var res=await S.stripe.confirmCardSetup(data.client_secret,{
        payment_method:{ card:S.card, billing_details:{ name:(name.trim()||email||'Client'), email:email||undefined } }
      });
      if(res.error){ setError(res.error.message||'Erreur d’authentification'); return; }
      var pm=res.setupIntent&&res.setupIntent.payment_method; if(!pm){ setError('Aucun moyen de paiement retourné'); return; }
      await attachPaymentMethod(pm, data.customer);
      window.__EDENA_SI_DONE__=true;
      var form=document.querySelector('form.checkout');
      if(window.jQuery&&jQuery(form).length){ jQuery(form).off('checkout_place_order'); jQuery(form).trigger('submit'); }
      else { form&&form.submit(); }
    }catch(e){ console.error('[EDENA] submit error',e); setError(e.message||'Erreur de garantie'); }
  }

  function ensureCardMounted(){
    if(!hasGuaranteeBlock()) return;
    whenStripeReady(function(){
      var pk=(window.EDENA_STRIPE_OPTS&&EDENA_STRIPE_OPTS.publishableKey)||null; if(!pk){ console.error('[EDENA] PK introuvable'); return; }
      if(!S.stripe) S.stripe=Stripe(pk);
      if(!S.elements) S.elements=S.stripe.elements({ locale:'fr' });
      if(!S.card){
        S.card=S.elements.create('card',{
          hidePostalCode:true,
          style:{ base:{ color:'#111827', iconColor:'#111827', fontSize:'16px', '::placeholder':{ color:'#6B7280' } },
                  invalid:{ color:'#DC2626', iconColor:'#DC2626' } }
        });
        var box=document.getElementById('edena-card-element');
        S.card.on('ready', function(){ box&&box.classList.add('is-ready'); });
        S.card.on('focus', function(){ box&&box.classList.add('is-focused'); });
        S.card.on('blur',  function(){ box&&box.classList.remove('is-focused'); });
        S.card.on('change',function(ev){
          if(!box) return;
          box.classList.toggle('is-empty', !!ev.empty);
          box.classList.toggle('is-invalid', !!ev.error);
          var err=document.getElementById('edena-card-errors');
          if(err) err.textContent=ev.error ? ev.error.message : '';
        });
      }
      var host=document.getElementById('edena-card-element'); var hasIframe=!!(host&&host.querySelector('iframe'));
      if(!hasIframe){ try{S.card.unmount();}catch(e){} S.card.mount('#edena-card-element'); S.cardMountedTo='edena-card-element'; }
      bindPlaceOrder();
    });
  }

  if(document.readyState==='loading'){ document.addEventListener('DOMContentLoaded', ensureCardMounted); }
  else { ensureCardMounted(); }

  if(window.jQuery&&window.jQuery(document.body)){
    var _deb=false;
    window.jQuery(document.body).on('updated_checkout', function(){
      window.__EDENA_SI_DONE__ = false;
      if(_deb) return; _deb=true;
      setTimeout(function(){ _deb=false; ensureCardMounted(); }, 120);
    });
  }
})();