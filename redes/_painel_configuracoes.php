<section class="arc-redes-secao" id="tab-preferences">
            <div class="card">
                
                <p class="text-muted">Estas opções pertencem somente a esta rede e não alteram as preferências das demais redes cadastradas.</p>
                <form method="post" action="<?=arc_h(arc_workspace_base_url())?>redes/servidor.php?network=<?= (int)$networkId ?>&amp;agent=<?= (int)$selectedAgentId ?>">
                    <input type="hidden" name="_csrf" value="<?= arc_h($csrf) ?>">
                    <input type="hidden" name="action" value="network_preferences_update">
                    <div class="form-field">
                        <label>Intervalo de atualização do monitor
                            <select name="monitor_refresh">
                                <?php foreach (['instant'=>'Instantâneo (consulta a cada 1 segundo)','1'=>'01 segundo','5'=>'05 segundos','10'=>'10 segundos','15'=>'15 segundos','30'=>'30 segundos','60'=>'60 segundos'] as $v=>$label): ?><option value="<?= $v ?>" <?= $networkPrefs['monitor_refresh']===$v?'selected':'' ?>><?= $label ?></option><?php endforeach; ?>
                            </select>
                        </label>
                        <label>Limite de latência para alerta
                            <select name="alert_latency">
                                <?php foreach (['80','100','150','200','300'] as $v): ?><option value="<?= $v ?>" <?= $networkPrefs['alert_latency']===$v?'selected':'' ?>><?= $v ?> ms</option><?php endforeach; ?>
                            </select>
                        </label>
                    </div>
                    <label class="pref-switch"><input type="checkbox" name="alert_low_speed" <?= $networkPrefs['alert_low_speed']==='1'?'checked':'' ?>><span><b>Alertar velocidade baixa</b><small>Ativa a preferência de alerta para testes abaixo do esperado nesta rede.</small></span></label>
                    <label class="pref-switch"><input type="checkbox" name="email_alerts" <?= $networkPrefs['email_alerts']==='1'?'checked':'' ?>><span><b>Notificações por e-mail</b><small>Preferência de recebimento de avisos relacionados a esta rede.</small></span></label>
                    <button class="button" type="submit">Salvar preferências da rede</button>
                </form>
            </div>
        </section>
