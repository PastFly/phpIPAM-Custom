<?php
declare(strict_types=1);

/** Administration fragment for Microsoft DNS configuration + autonomous collector controls. */
final class IntegrationDnsAdminView
{
    private static function h(mixed $v): string
    {
        return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    }

    private static function input(string $label,string $name,mixed $value='',string $type='text'): string
    {
        $value=$type==='password'?'':(string)$value;
        return '<div class="form-group"><label>'.self::h($label).
            '<input class="form-control input-sm" type="'.self::h($type).'" name="'.self::h($name).
            '" value="'.self::h($value).'" autocomplete="'.($type==='password'?'new-password':'off').'"></label></div>';
    }

    private static function identity(?array $row): string
    {
        return '<input type="hidden" name="id" value="'.self::h($row['id']??'').'">'.
            '<input type="hidden" name="expected_revision" value="'.self::h($row['revision']??0).'">';
    }

    private static function jobLabel(?array $job): string
    {
        if($job===null) return 'ещё не запускалось';
        $state=(string)($job['state']??'unknown');
        $code=$job['result_code']??null;
        $suffix=is_string($code)&&$code!==''?' / '.$code:'';
        return $state.$suffix;
    }

    private static function zoneResult(?array $result): string
    {
        if ($result===null || !is_array($result['zones']??null)) return '';
        $zones=$result['zones'];
        $finished=(int)($result['finished_at']??0);
        $html='<details class="dns-zone-results"><summary>Полученные зоны: '.count($zones).'</summary>';
        if ($finished>0) $html.='<p>Получено: '.self::h(gmdate('Y-m-d H:i:s',$finished)).' UTC</p>';
        $html.='<p class="text-muted">Последний успешный результат для текущего подключения и сборщика. Результат может быть удалён спустя 24 часа.</p>';
        if (!$zones) return $html.'<p>Зоны не найдены.</p></details>';
        $html.='<div class="table-responsive"><table class="table table-striped table-condensed"><thead><tr>'.
            '<th scope="col">Зона</th><th scope="col">Тип</th><th scope="col">В Active Directory</th><th scope="col">Обратная зона</th></tr></thead><tbody>';
        foreach ($zones as $zone) {
            $html.='<tr><td>'.self::h($zone['name']===''?'.':$zone['name']).'</td>'.
                '<td>'.self::h($zone['zone_type']).'</td><td>'.($zone['ds_integrated']?'Да':'Нет').'</td>'.
                '<td>'.($zone['reverse_lookup']?'Да':'Нет').'</td></tr>';
        }
        return $html.'</tbody></table></div></details>';
    }

    private function profile(?array $p): string
    {
        $affected=array_column($p['connections']??[],'id');
        $html='<form data-dns-action="save_profile">'.self::identity($p).
            '<input type="hidden" name="acknowledge_connections" value="'.self::h(json_encode($affected,JSON_THROW_ON_ERROR)).'">';
        $html.=self::input('Название профиля','name',$p['name']??'').
            self::input('Логин: UPN или домен\\логин','username',$p['username']??'');
        $html.='<div class="form-group"><label>Пароль <select class="form-control input-sm" name="password_action">';
        if($p!==null&&$p['password_set']) $html.='<option value="unchanged">Не изменять сохранённый пароль</option>';
        $html.='<option value="replace">Задать новый сохранённый пароль</option></select></label></div>'.
            self::input('Новый пароль','password','','password');
        $html.='<p class="text-muted">'.($p!==null&&$p['password_set']?
            'Пароль задан. Сохранённое значение не показывается.':'Пароль не задан.').'</p>';
        if($affected) {
            $html.='<p>Этот профиль используется подключениями: '.
                implode(', ',array_map(static fn($c)=>self::h($c['name']),$p['connections'])).
                '. Замена или отзыв потребуют повторной проверки доступа.</p>';
        }
        $html.='<p class="text-muted">Изменяется только сохранённый пароль интеграции, не пароль учётной записи в AD.</p>'.
            '<button type="submit" class="btn btn-default btn-sm">Сохранить профиль</button>';
        if($p!==null) {
            $html.=' <button type="button" class="btn btn-default btn-sm" data-dns-operation="revoke_profile">Отозвать сохранённый пароль</button>';
            $html.=' <button type="button" class="btn btn-default btn-sm" data-dns-operation="delete_profile"'.($affected?' disabled':'').'>Удалить профиль</button>';
        }
        return $html.'</form>';
    }

    private function collectorRegistration(): string
    {
        return '<form data-dns-action="register_collector">'.self::identity(null).
            self::input('Название Windows-сборщика','name','').
            '<p class="text-muted">После создания control token показывается только один раз. Он нужен только для первичной установки сборщика.</p>'.
            '<button type="submit" class="btn btn-default btn-sm">Создать сборщик</button></form>';
    }

    private function collector(array $collector): string
    {
        $revoked=($collector['revoked']??false)===true;
        $html='<form data-dns-action="collector">'.self::identity($collector).
            '<p><strong>'.self::h($collector['name']).'</strong> — '.($revoked?'отозван':'активен').'</p>'.
            '<p class="text-muted">ID: '.self::h($collector['id']).'. Control token повторно не отображается.</p>';
        if(!$revoked) {
            $html.='<button type="button" class="btn btn-default btn-sm" data-dns-operation="revoke_collector">Отозвать сборщик</button>';
        }
        return $html.'</form>';
    }

    private function connection(?array $c,array $profiles,array $collectors,?array $runtime,bool $controlAvailable): string
    {
        $html='<form data-dns-action="save_connection">'.self::identity($c);
        $html.=self::input('Название подключения','name',$c['name']??'').
            self::input('Полное доменное имя DNS-сервера','server',$c['server']??'');
        $html.=self::input('Интервал сбора, 60–3600 секунд','interval_seconds',$c['interval_seconds']??300,'number');

        $html.='<div class="form-group"><label>Способ доступа <select class="form-control input-sm" name="credential_mode">';
        foreach(['profile'=>'Сохранённый профиль УЗ','collector_identity'=>'Учётная запись Windows-сборщика'] as $mode=>$label) {
            $html.='<option value="'.$mode.'"'.(($c['credential_mode']??'profile')===$mode?' selected':'').'>'.$label.'</option>';
        }
        $html.='</select></label></div><div class="form-group"><label>Профиль <select class="form-control input-sm" name="profile_id">'.
            '<option value="">Выберите профиль</option>';
        foreach($profiles as $p) {
            $html.='<option value="'.self::h($p['id']).'"'.(($c['profile_id']??null)===$p['id']?' selected':'').'>'.
                self::h($p['name']).($p['password_set']?'':' — пароль отозван').'</option>';
        }
        $html.='</select></label></div>'.
            '<label><input type="checkbox" name="share_profile"> Разрешаю использовать профиль совместно с другими подключениями</label>';

        if($c===null) {
            $html.='<p class="text-muted">После сохранения назначьте Windows-сборщик и выполните проверку доступа.</p>'.
                '<button type="submit" class="btn btn-default btn-sm">Сохранить подключение</button>';
            return $html.'</form>';
        }

        $assigned=(bool)($runtime['assigned']??false);
        $revoked=($runtime['collector_revoked']??null)===true;
        $collectorId=$runtime['collector_id']??null;
        $collectorName=$runtime['collector_name']??null;
        $assignmentRevision=(int)($runtime['assignment_revision']??0);
        $enabled=($c['enabled']??false)===true;

        $html.='<div class="alert alert-info" data-dns-runtime>'.
            '<strong>Автоматический сбор:</strong> '.($enabled?'включён':'выключен').'<br>'.
            '<strong>Сборщик:</strong> '.($assigned&&!$revoked?self::h($collectorName??'назначен'):'не назначен или недоступен').'<br>'.
            '<strong>Проверка доступа:</strong> '.self::h(self::jobLabel($runtime['test_connection']??null)).'<br>'.
            '<strong>Получение зон:</strong> '.self::h(self::jobLabel($runtime['list_zones']??null)).
            '</div>';
        $html.=self::zoneResult($runtime['zone_result']??null);

        $html.='<div class="form-group"><label>Назначенный Windows-сборщик '.
            '<select class="form-control input-sm" name="collector_assignment"><option value="">Выберите сборщик</option>';
        foreach($collectors as $collector) {
            if(($collector['revoked']??false)===true) continue;
            $html.='<option value="'.self::h($collector['id']).'"'.($collectorId===$collector['id']?' selected':'').'>'.
                self::h($collector['name']).'</option>';
        }
        $html.='</select></label>'.
            '<input type="hidden" name="assignment_revision" value="'.self::h($assignmentRevision).'"> '.
            '<button type="button" class="btn btn-default btn-sm" data-dns-operation="save_assignment"'.
            ($enabled||!$collectors?' disabled':'').'>Назначить сборщик</button></div>';

        $html.='<p class="text-muted">После включения phpIPAM сам ставит задания по указанному интервалу, а Windows Scheduled Task забирает их автоматически.</p>'.
            '<button type="submit" class="btn btn-default btn-sm">Сохранить подключение</button>';

        $html.=' <button type="button" class="btn btn-default btn-sm" data-dns-operation="delete_connection"'.
            ($enabled?' disabled':'').'>Удалить подключение</button>';

        $canControl=$controlAvailable&&$assigned&&!$revoked;
        $html.=' <button type="button" class="btn btn-default btn-sm" data-dns-operation="queue_test_connection"'.
            ($canControl?'':' disabled').'>Проверить доступ</button>';
        $html.=' <button type="button" class="btn btn-default btn-sm" data-dns-operation="queue_list_zones"'.
            ($canControl?'':' disabled').'>Получить зоны</button>';
        $toggleAllowed=$enabled||$canControl;
        $html.=' <button type="button" class="btn btn-primary btn-sm" data-dns-operation="set_collection_enabled" data-dns-enabled="'.
            ($enabled?'0':'1').'"'.($toggleAllowed?'':' disabled').'>'.
            ($enabled?'Отключить сбор':'Включить сбор').'</button>';

        return $html.'</form>';
    }

    public function render(array $state,string $csrf): string
    {
        $profiles=is_array($state['profiles']??null)?$state['profiles']:[];
        $connections=is_array($state['connections']??null)?$state['connections']:[];
        $runtime=is_array($state['connection_runtime']??null)?$state['connection_runtime']:[];
        $control=is_array($state['collector_control']??null)?$state['collector_control']:[];
        $collectors=is_array($control['collectors']??null)?$control['collectors']:[];
        $controlAvailable=($state['collector_control_available']??false)===true;

        $html='<section id="dns-configuration" data-csrf="'.self::h($csrf).'">'.
            '<h3>Microsoft DNS — подключения и автоматический сбор</h3>'.
            '<div data-dns-message role="status" aria-live="polite"></div>'.
            '<div class="alert alert-info" data-dns-enrollment hidden>'.
            '<strong>Control token сборщика — показывается один раз.</strong><br>'.
            '<code data-dns-enrollment-token></code><br>'.
            '<span class="text-muted">Сохраните его только для первичной установки. После обновления страницы token исчезнет.</span>'.
            '</div>';

        $html.='<p class="text-muted">Обычная работа выполняется автоматически: phpIPAM планирует задания, установленный Windows-сборщик выполняет их без ручного PowerShell.</p>';

        $html.='<h4>Подключения</h4><details><summary>Новое подключение</summary>'.
            $this->connection(null,$profiles,$collectors,null,$controlAvailable).'</details>';

        foreach($connections as $c) {
            $enabled=($c['enabled']??false)===true;
            $summary=self::h($c['name']).' — '.self::h($c['server']).' ('.
                ($enabled?'сбор включён':'сбор отключён').')';
            $html.='<details><summary>'.$summary.'</summary>'.
                $this->connection($c,$profiles,$collectors,$runtime[$c['id']]??null,$controlAvailable).'</details>';
        }

        $html.='<h4>Windows-сборщики</h4><details><summary>Новый сборщик</summary>'.$this->collectorRegistration().'</details>';
        foreach($collectors as $collector) {
            $html.='<details><summary>'.self::h($collector['name']).' — '.
                (($collector['revoked']??false)?'отозван':'активен').'</summary>'.$this->collector($collector).'</details>';
        }

        $html.='<h4>Профили доступа</h4><details><summary>Новый профиль</summary>'.$this->profile(null).'</details>';
        foreach($profiles as $p) {
            $html.='<details><summary>'.self::h($p['name']).' — '.self::h($p['username']).'</summary>'.$this->profile($p).'</details>';
        }

        return $html.'</section>';
    }
}
