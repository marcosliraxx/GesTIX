<?php /* Caminho: includes/network-register-modal.php — Formulário único de cadastro de redes. */ 
require_once __DIR__ . '/error-logging.php';
?>
<div class="modal-overlay" id="modal-register" role="dialog" aria-modal="true" aria-labelledby="register-modal-title" aria-hidden="true">
    <div class="modal">
        <div class="modal-header">
            <h2 id="register-modal-title">Registrar nova rede</h2>
            <button class="modal-dismiss" type="button" data-close-modal><i class="fa fa-times" aria-hidden="true"></i></button>
        </div>
        <div class="modal-content">
            <form method="post" enctype="multipart/form-data" id="form-register" action="<?=arc_h(arc_workspace_base_url())?>pages/painel.php">
                <input type="hidden" name="_csrf" value="<?= arc_h(arc_csrf_token()) ?>">
                <input type="hidden" name="action" value="network_create">

                <div class="arc-register-grid">
                    <div class="arc-register-primary">
                        <label class="arc-logo-picker" title="Selecionar a logo da rede">
                            <span id="arc-logo-preview"><i class="fa fa-image" aria-hidden="true"></i></span>
                            <span class="arc-logo-caption">Logo da rede</span>
                            <input id="reg-logo" type="file" name="network_logo" accept="image/png,image/jpeg,image/webp" aria-label="Selecionar logo (até 2 MB)">
                        </label>
                        <div class="arc-register-identify">
                            <label class="arc-field">CPF ou CNPJ <span class="arc-doc-wrap"><input name="doc_number" id="doc-number" inputmode="numeric" maxlength="18" autocomplete="off" placeholder="CPF ou CNPJ"><span class="doc-badge" id="doc-badge">Documento</span></span></label>
                            <label class="arc-field">Nome da rede<input name="network_name" id="reg-name" maxlength="100" required placeholder="Matriz, filial, escritório..."></label>
                        </div>
                    </div>
                    <p class="doc-status span2" id="doc-status" role="status" aria-live="polite">CNPJ: consulta automática ao completar os 14 dígitos.</p>
                    <label class="arc-field">Razão social / cliente<input name="client_name" id="reg-client" maxlength="160" required></label>
                    <label class="arc-field">Nome fantasia<input name="trade_name" id="reg-fantasy" maxlength="160"></label>
                    <label class="arc-field">Responsável<input name="contact_name" id="reg-contact" maxlength="160"></label>
                    <label class="arc-field">Telefone<input name="contact_phone" id="reg-phone" maxlength="40" inputmode="tel"></label>
                    <label class="arc-field">E-mail da empresa<input name="company_email" id="reg-email" type="email" maxlength="160"></label>
                    <label class="arc-field">CEP<input name="zip_code" id="reg-cep" inputmode="numeric" maxlength="9"></label>
                    <label class="arc-field">UF<input name="state_code" id="reg-uf" maxlength="2"></label>
                    <label class="arc-field">Município<input name="city_name" id="reg-city" maxlength="100"></label>
                    <label class="arc-field span2">Endereço completo<input name="address" id="reg-address" maxlength="255"></label>
                    <details class="arc-register-more span2"><summary><i class="fa fa-sliders" aria-hidden="true"></i> Mais detalhes do cadastro (opcional)</summary>
                        <div class="arc-register-more-grid">
                            <label class="arc-field">Logradouro<input name="street_name" id="reg-street" maxlength="160"></label>
                            <label class="arc-field">Número<input name="street_number" id="reg-number" maxlength="20"></label>
                            <label class="arc-field">Complemento<input name="address_extra" id="reg-complement" maxlength="100"></label>
                            <label class="arc-field">Bairro<input name="district_name" id="reg-district" maxlength="100"></label>
                            <label class="arc-field">Situação cadastral<input name="registration_status" id="reg-status" readonly></label>
                            <label class="arc-field">CNAE principal<input name="main_activity" id="reg-cnae" readonly></label>
                            <label class="arc-field">Natureza jurídica<input name="legal_nature" id="reg-legal" readonly></label>
                            <label class="arc-field">Data de abertura<input name="opening_date" id="reg-opening" readonly></label>
                            <label class="arc-field span2">Observações<textarea name="notes" id="reg-notes" maxlength="5000" rows="2"></textarea></label>
                        </div>
                    </details>
                </div>
                <div class="arc-register-footer"><span>O código da rede será gerado automaticamente.</span><button class="button primary" type="submit"><i class="fa fa-check" aria-hidden="true"></i> Registrar rede</button></div>

            </form>
        </div>
    </div>
</div>

<script>
(function(){
'use strict';
var modal=document.getElementById('modal-register');
if(!modal)return;
/* Delegação: o botão do footer é renderizado depois deste script. */
var previousFocus=null;
function open(){previousFocus=document.activeElement;modal.classList.add('open');modal.setAttribute('aria-hidden','false');document.body.classList.add('arc-modal-active');document.getElementById('doc-number')?.focus();}
function close(){modal.classList.remove('open');modal.setAttribute('aria-hidden','true');document.body.classList.remove('arc-modal-active');previousFocus?.focus();}
document.addEventListener('click',function(e){
    if(e.target.closest('[data-open-register]')) { e.preventDefault(); open(); }
});
modal.querySelector('[data-close-modal]')?.addEventListener('click',close);
modal.addEventListener('click',e=>{if(e.target===modal)close();});
document.addEventListener('keydown',e=>{if(e.key==='Escape'&&modal.classList.contains('open'))close();});
if(new URLSearchParams(location.search).get('nova_rede')==='1')open();
    /* Documento com reconhecimento progressivo e consulta de CNPJ. */
    var docInput = document.getElementById('doc-number');
    var docBadge = document.getElementById('doc-badge');
    var docStatus = document.getElementById('doc-status');
    var lastCnpj = '', requestController = null, lookupTimer = null;
    function digits(value) { return String(value || '').replace(/\D/g, ''); }
    function maskDocument(value) {
        var d = digits(value).slice(0,14);
        if (d.length <= 11) return d.replace(/^(\d{3})(\d)/,'$1.$2').replace(/^(\d{3})\.(\d{3})(\d)/,'$1.$2.$3').replace(/(\d{3})(\d{1,2})$/,'$1-$2');
        return d.replace(/^(\d{2})(\d)/,'$1.$2').replace(/^(\d{2})\.(\d{3})(\d)/,'$1.$2.$3').replace(/\.(\d{3})(\d)/,'.$1/$2').replace(/(\d{4})(\d{1,2})$/,'$1-$2');
    }
    function put(id, value) { var el = document.getElementById(id); if (el) el.value = value == null ? '' : String(value); }
    function status(message, type) { docStatus.textContent = message; docStatus.className = 'doc-status span2' + (type ? ' ' + type : ''); }
    function addressFromFields() {
        var get = function(id){ return (document.getElementById(id)?.value || '').trim(); };
        put('reg-address', [get('reg-street'), get('reg-number'), get('reg-complement'), get('reg-district'), [get('reg-city'),get('reg-uf')].filter(Boolean).join('/'), get('reg-cep') ? 'CEP ' + get('reg-cep') : ''].filter(Boolean).join(', ').slice(0,255));
    }
    ['reg-street','reg-number','reg-complement','reg-district','reg-city','reg-uf','reg-cep'].forEach(function(id) {
        var el=document.getElementById(id); if (el) el.addEventListener('change', addressFromFields);
    });
    function applyCnpj(data) {
        put('reg-client', data.razao_social || '');
        put('reg-fantasy', data.nome_fantasia || '');
        put('reg-name', data.nome_fantasia || data.razao_social || '');
        put('reg-contact', data.nome_fantasia || data.razao_social || '');
        put('reg-phone', data.ddd_telefone_1 || data.ddd_telefone_2 || '');
        put('reg-email', data.email || '');
        put('reg-cep', String(data.cep || '').replace(/^(\d{5})(\d{3})$/,'$1-$2'));
        put('reg-uf', data.uf || '');
        put('reg-street', data.logradouro || '');
        put('reg-number', data.numero || '');
        put('reg-complement', data.complemento || '');
        put('reg-district', data.bairro || '');
        put('reg-city', data.municipio || '');
        put('reg-status', data.descricao_situacao_cadastral || '');
        var cnae = data.cnae_fiscal_descricao || data.cnae_fiscal || '';
        put('reg-cnae', cnae);
        put('reg-legal', data.natureza_juridica || '');
        put('reg-opening', data.data_inicio_atividade || '');
        addressFromFields();
        status('Dados públicos do CNPJ preenchidos. Confira as informações antes de salvar.', 'success');
    }
    async function lookupCnpj(cnpj) {
        if (requestController) requestController.abort();
        requestController = new AbortController();
        status('Consultando dados públicos do CNPJ...', 'busy');
        try {
            var response = await fetch('pages/painel.php?ajax=cnpj_lookup&cnpj=' + encodeURIComponent(cnpj), {signal:requestController.signal,credentials:'same-origin',headers:{'Accept':'application/json'}});
            var payload=await response.json();
            if (!response.ok || !payload.ok) throw new Error(payload.error || 'Consulta indisponível');
            var data = payload.data;
            if (digits(docInput.value) !== cnpj) return;
            if (!data || !data.razao_social) throw new Error('CNPJ não encontrado');
            applyCnpj(data);
        } catch (error) {
            if (error.name === 'AbortError') return;
            if (digits(docInput.value) === cnpj) status(error.message || 'Consulta indisponível. Preencha os campos manualmente.', 'error');
            lastCnpj = '';
        }
    }
    var logoFile=document.getElementById('reg-logo'), logoPreview=document.getElementById('arc-logo-preview');
    if(logoFile&&logoPreview)logoFile.addEventListener('change',function(){
        var file=this.files&&this.files[0];if(!file)return;
        if(file.size>2097152||!/^image\/(png|jpeg|webp)$/.test(file.type)){
            this.value='';status('A logo deve ser PNG, JPG ou WEBP, com até 2 MB.','error');return;
        }
        var reader=new FileReader();reader.onload=function(){var image=document.createElement('img');image.src=String(reader.result);image.alt='Prévia da logo';logoPreview.replaceChildren(image);};reader.readAsDataURL(file);
    });
    if (docInput) docInput.addEventListener('input', function() {
        var caret=docInput.selectionStart||0;
        var before=digits(docInput.value.slice(0,caret)).length;
        var d=digits(docInput.value).slice(0,14);
        var formatted=maskDocument(d);
        if(docInput.value!==formatted){
            docInput.value=formatted;
            var pos=0,count=0;
            while(pos<formatted.length&&count<before){if(/\d/.test(formatted.charAt(pos)))count++;pos++;}
            docInput.setSelectionRange(pos,pos);
        }
        var type=d.length>11?'CNPJ':d.length===11?'CPF':'CPF/CNPJ';
        docBadge.textContent=type;
        docBadge.className='doc-badge '+(type==='CNPJ'?'cnpj':type==='CPF'?'cpf':'');
        if(lookupTimer)clearTimeout(lookupTimer);
        if(d.length!==14&&requestController){requestController.abort();requestController=null;}
        if(!d.length)status('Digite CPF ou CNPJ para identificar o documento.');
        else if(d.length===11)status('CPF identificado. Para CNPJ, continue digitando até 14 dígitos.');
        else if(d.length<14)status('Documento: '+d.length+' dígitos informados.');
        if(d.length===14&&d!==lastCnpj){
            lookupTimer=setTimeout(function(){if(digits(docInput.value)===d){lastCnpj=d;lookupCnpj(d);}},450);
        }
    });


})();
</script>
