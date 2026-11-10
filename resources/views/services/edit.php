<div class="d-flex justify-content-between align-items-center mb-4">
    <h2 class="mb-0"><i class="bi bi-pencil-square"></i> <?= __('SERVICES_EDIT') ?>: <?= e($service['name']) ?></h2>
    <a href="/services/<?= (int)$service['id'] ?>" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> <?= __('COMMON_BACK') ?></a>
</div>

<div class="card border-0 shadow-sm" style="max-width:720px">
    <div class="card-body">
        <form method="POST" action="/services/<?= (int)$service['id'] ?>/edit">
            <?= csrf_field() ?>
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label fw-semibold"><?= __('COMMON_CODE') ?> <span class="text-danger">*</span></label>
                    <input type="text" name="code" class="form-control" required maxlength="50"
                           style="text-transform:uppercase" value="<?= e($service['code']) ?>">
                </div>
                <div class="col-md-8">
                    <label class="form-label fw-semibold"><?= __('COMMON_NAME') ?> <span class="text-danger">*</span></label>
                    <input type="text" name="name" class="form-control" required maxlength="150"
                           value="<?= e($service['name']) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold"><?= __('SERVICES_CATEGORY') ?> <span class="text-danger">*</span></label>
                    <select name="category_id" class="form-select" required>
                        <?php foreach ($categories as $cat): ?>
                        <option value="<?= (int)$cat['id'] ?>" <?= ($service['category_id'] == $cat['id']) ? 'selected' : '' ?>>
                            <?= e($cat['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">
                        Служба-владелец
                        <i class="bi bi-info-circle text-muted ms-1"
                           data-bs-toggle="tooltip"
                           title="Какая служба вносит эту услугу по рейсу и видит её в своей форме. Если не выбрать — услуга не появится ни у кого в списке выбора."></i>
                        <span class="text-danger">*</span>
                    </label>
                    <?php
                    $ownerRoles = [
                        'sop'        => 'СОП — Служба организации перевозок',
                        'spo'        => 'СПО — Служба перронного обеспечения',
                        'sst'        => 'ССТ — Служба специального транспорта',
                        'aero'       => 'Аэродромная служба',
                        'spasop'     => 'СПАСОП',
                        // ДОБАВЛЕНО (14.09.2026, раунд 41): новая схема распределения услуг.
                        'gsm'        => 'ГСМ — Служба горюче-смазочных материалов',
                        'medical'    => 'Здрав.пункт — Медицинская служба',
                        'balance'    => 'ОЦ — Отдел центровки',
                        'supervisor' => 'Супервайзер',
                    ];
                    ?>
                    <select name="owner_role" class="form-select" required>
                        <option value="">— Выберите службу —</option>
                        <?php foreach ($ownerRoles as $value => $label): ?>
                        <option value="<?= $value ?>" <?= ($service['owner_role'] ?? '') === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">
                        MTOW от, т
                        <i class="bi bi-info-circle text-muted ms-1"
                           data-bs-toggle="tooltip"
                           title="Заполняйте только для услуг с диапазоном по массе ВС (рамп-хэндлинг, деайсинг и т.п.). Оставьте пустым, если услуга не зависит от MTOW."></i>
                    </label>
                    <input type="number" step="0.01" name="mtow_min" class="form-control"
                           value="<?= e($service['mtow_min'] ?? '') ?>" placeholder="напр. 25.10">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">MTOW до, т</label>
                    <input type="number" step="0.01" name="mtow_max" class="form-control"
                           value="<?= e($service['mtow_max'] ?? '') ?>" placeholder="напр. 50.00">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">
                        Взаимоисключающая группа
                        <i class="bi bi-info-circle text-muted ms-1"
                           data-bs-toggle="tooltip"
                           title="Услуги с одинаковым названием группы не могут быть оказаны на одном рейсе одновременно (напр. DCS_AP и DCS_VPN — обе в группе DCS_CONNECTION). Оставьте пустым, если для услуги это не актуально."></i>
                    </label>
                    <input type="text" name="exclusive_group" class="form-control" maxlength="50"
                           style="text-transform:uppercase"
                           value="<?= e($service['exclusive_group'] ?? '') ?>" placeholder="напр. DCS_CONNECTION">
                </div>
                <div class="col-md-12">
                    <div class="form-check form-switch mt-2">
                        <input type="checkbox" class="form-check-input" name="requires_manual_quantity"
                               id="requires_manual_quantity" value="1"
                               <?= !empty($service['requires_manual_quantity']) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="requires_manual_quantity">
                            Требует ввода фактического количества супервайзером
                            <i class="bi bi-info-circle text-muted ms-1"
                               data-bs-toggle="tooltip"
                               title="Включите для услуг, где количество реально меняется от рейса к рейсу — пассажиры, места багажа, часы, литры и т.п. При подтверждении на планшете супервайзер увидит поле ввода вместо автоматического количества по умолчанию."></i>
                        </label>
                    </div>
                    <!-- ДОБАВЛЕНО (29.09.2026, раунд 64, по прямому указанию
                         пользователя): вместо ввода количества супервайзером
                         вручную — считать его сервером из времени начала/
                         окончания (только для услуг с тайминг-флоу
                         "Начать/Завершить", т.е. no_timing=0). Округление —
                         в большую сторону, шаг 30 минут. См.
                         app/Controllers/ServiceOrderController.php::
                         calcQuantityFromTiming(). -->
                    <div class="form-check form-switch mt-2">
                        <input type="checkbox" class="form-check-input" name="quantity_from_timing"
                               id="quantity_from_timing" value="1"
                               <?= !empty($service['quantity_from_timing']) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="quantity_from_timing">
                            Количество считать автоматически по времени (вместо ручного ввода)
                            <i class="bi bi-info-circle text-muted ms-1"
                               data-bs-toggle="tooltip"
                               title="Включите для почасовых услуг с реальным процессом «Начать/Завершить» (сейчас — ГПУ, кондиционирование воздуха), где количество часов должно считаться системой из фактического времени оказания, а не вводиться супервайзером вручную. Округление вверх с шагом 30 минут (напр. 47 минут → 1 час, 61 минута → 1.5 часа). Имеет смысл только при выключенном «Без тайминга» ниже — иначе считать не из чего. При включении этого флажка ручной ввод количества на экране супервайзера отключается для этой услуги (после сохранения также снимите выше флажок «Требует ввода фактического количества супервайзером», если он был включён)."></i>
                        </label>
                    </div>
                    <!-- ДОБАВЛЕНО (06.08.2026) -->
                    <div class="form-check form-switch mt-2">
                        <input type="checkbox" class="form-check-input" name="requires_dispatch_notice"
                               id="requires_dispatch_notice" value="1"
                               <?= !empty($service['requires_dispatch_notice']) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="requires_dispatch_notice">
                            Требует отметки "Техника выехала"
                            <i class="bi bi-info-circle text-muted ms-1"
                               data-bs-toggle="tooltip"
                               title="Включите для услуг, где важно, что транспорт/техника физически выдвинулись к борту заранее (спецтехника, автобусы и т.п.). У службы на странице рейса появится кнопка «Техника выехала» — при нажатии супервайзер получит уведомление."></i>
                        </label>
                    </div>
                    <!-- ДОБАВЛЕНО (14.08.2026, раунд 6) -->
                    <div class="form-check form-switch mt-2">
                        <input type="checkbox" class="form-check-input" name="requires_tech_personnel"
                               id="requires_tech_personnel" value="1"
                               <?= !empty($service['requires_tech_personnel']) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="requires_tech_personnel">
                            Учитывать в тех.персонале ССТ (авторасчёт TECH_STAFF)
                            <i class="bi bi-info-circle text-muted ms-1"
                               data-bs-toggle="tooltip"
                               title="Включите, если фактическое оказание этой услуги означает выезд единицы техники и/или человека к борту ВС (напр. заправка водой, чистка туалета МА-7, GPU, погрузка/выгрузка багажа и т.п.). Каждая такая оказанная услуга-строка по рейсу добавляет 1 человека к автоматически рассчитываемой услуге «Тех персонал для обслуж. GSE» (TECH_STAFF) при сборке акта. НЕ включайте для услуг, где спецтехника/водитель — это отдельная служба, а не тех.персонал ССТ (напр. автобус для пассажиров/экипажа — услуга СОП)."></i>
                        </label>
                    </div>
                    <!-- ДОБАВЛЕНО (14.08.2026, раунд 7) -->
                    <div class="form-check form-switch mt-2">
                        <input type="checkbox" class="form-check-input" name="is_blanket"
                               id="is_blanket" value="1"
                               <?= !empty($service['is_blanket']) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="is_blanket">
                            Оказывается на каждый рейс без отдельной заявки (бейдж «По умолчанию» в акте)
                            <i class="bi bi-info-circle text-muted ms-1"
                               data-bs-toggle="tooltip"
                               title="Включите только для услуг, которые оказываются на КАЖДОМ рейсе автоматически, а не по факту чьей-то конкретной заявки/запроса — напр. взлёт-посадка, авиабезопасность, лидирование ВС. НЕ включайте для услуг, которые супервайзер/служба реально запрашивает и подтверждает по факту (пассажиры, багаж, автобус, спецтехника и т.п.) — даже если система сама подставляет их в акт автоматически, для клиента это должно выглядеть как оказанная по запросу услуга, а не «по умолчанию». Влияет только на отображение бейджа в строках акта, не на авторасчёт сумм."></i>
                        </label>
                    </div>
                    <!-- ДОБАВЛЕНО (17.08.2026, раунд 9) -->
                    <div class="form-check form-switch mt-2">
                        <input type="checkbox" class="form-check-input" name="is_non_billable"
                               id="is_non_billable" value="1"
                               <?= !empty($service['is_non_billable']) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="is_non_billable">
                            Без оплаты (не входит в сумму акта)
                            <i class="bi bi-info-circle text-muted ms-1"
                               data-bs-toggle="tooltip"
                               title="Включите для обязательных операций, время которых нужно фиксировать через обычные кнопки «Начать/Завершить», но за которые НЕ взимается плата — напр. установка/уборка колодок (CHOCKS). Служба по-прежнему подтверждает оказание услуги как обычно, но система не создаёт биллинговую запись и строка не попадает в акт/в сумму акта."></i>
                        </label>
                    </div>
                    <!-- ДОБАВЛЕНО (19.08.2026, раунд 21) -->
                    <div class="form-check form-switch mt-2">
                        <input type="checkbox" class="form-check-input" name="no_timing"
                               id="no_timing" value="1"
                               <?= !empty($service['no_timing']) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="no_timing">
                            Без тайминга — только «Оказано» (нет старта/завершения)
                            <i class="bi bi-info-circle text-muted ms-1"
                               data-bs-toggle="tooltip"
                               title="Включите для услуг, у которых физически нет процесса с началом и концом — это просто факт оказания в моменте (напр. МЕТЕО). На экране супервайзера вместо пары «Начать»/«Завершить» будет одна кнопка «Оказано», которая сразу подтверждает услугу и фиксирует один момент времени."></i>
                        </label>
                    </div>
                    <!-- ДОБАВЛЕНО (25.09.2026, раунд 58); ИЗМЕНЕНО (05.10.2026,
                         раунд 66): для формульных обязательных услуг
                         (services.is_mandatory=1 — TLF/AV_SEC/NIGHT/
                         OVER_PARK/PAX_SEC/MET_*/PAX_ARR/PAX_DEP/BAG_*_PC,
                         см. AutoChargeService) чекбокс теперь заблокирован —
                         это ровно тот случай, от которого уже предупреждала
                         подсказка ниже ("НЕ включайте..."), просто раньше
                         ничего не мешало случайно включить его руками. Сама
                         is_mandatory в этой форме не редактируется (меняется
                         только напрямую в БД), поэтому заблокировать можно
                         безусловно, без доп. логики сохранения. -->
                    <?php $isMandatoryFormula = !empty($service['is_mandatory']); ?>
                    <div class="form-check form-switch mt-2">
                        <input type="checkbox" class="form-check-input" name="allow_repeat"
                               id="allow_repeat" value="1"
                               <?= ($isMandatoryFormula ? false : !empty($service['allow_repeat'])) ? 'checked' : '' ?>
                               <?= $isMandatoryFormula ? 'disabled' : '' ?>>
                        <label class="form-check-label" for="allow_repeat">
                            Можно заказать повторно на одном рейсе
                            <i class="bi bi-info-circle text-muted ms-1"
                               data-bs-toggle="tooltip"
                               title="Включите для услуг, которые реально могут оказываться несколько раз за один рейс (напр. трап, ГПУ, кондиционирование воздуха) — на экране супервайзера у завершённой карточки появится кнопка «Заказать ещё раз», и каждый новый раз будет отдельно оплачен (отдельная строка в акте). НЕ включайте для услуг с формульным авторасчётом (взлёт-посадка, пассажирские/ночные/сверхнормативные сборы и т.п.) — для них допустим только один экземпляр за ротацию, повтор задвоит сумму."></i>
                        </label>
                        <?php if ($isMandatoryFormula): ?>
                        <div class="form-text text-warning">
                            <i class="bi bi-lock-fill"></i> Заблокировано: это формульная обязательная
                            услуга (считается автоматически системой за рейс/ротацию) — повторный
                            заказ задвоил бы сумму. Если это ошибочно — поправьте
                            <code>services.is_mandatory</code> напрямую в БД.
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Unit Name <span class="text-danger">*</span></label>
                    <input type="text" name="unit_name" class="form-control" required maxlength="50"
                           value="<?= e($service['unit_name']) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Default Currency <span class="text-danger">*</span></label>
                    <select name="default_currency_id" class="form-select" required>
                        <?php foreach ($currencies as $c): ?>
                        <option value="<?= (int)$c['id'] ?>" <?= ($service['default_currency_id'] == $c['id']) ? 'selected' : '' ?>>
                            <?= e($c['code']) ?> — <?= e($c['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label fw-semibold"><?= __('COMMON_DESCRIPTION') ?> </label>
                    <textarea name="description" class="form-control" rows="2"><?= e($service['description'] ?? '') ?></textarea>
                </div>
                <div class="col-md-4">
                    <div class="form-check mt-4">
                        <input type="checkbox" name="vat_applicable_domestic" id="vat_dom" class="form-check-input" value="1"
                               <?= $service['vat_applicable_domestic'] ? 'checked' : '' ?>>
                        <label class="form-check-label fw-semibold" for="vat_dom"><?= __('SERVICES_VAT_DOMESTIC') ?> </label>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="form-check mt-4">
                        <input type="checkbox" name="vat_applicable_international" id="vat_intl" class="form-check-input" value="1"
                               <?= $service['vat_applicable_international'] ? 'checked' : '' ?>>
                        <label class="form-check-label fw-semibold" for="vat_intl"><?= __('SERVICES_VAT_INTL') ?> </label>
                    </div>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold"><?= __('COMMON_STATUS') ?> </label>
                    <select name="is_active" class="form-select">
                        <option value="1" <?= $service['is_active'] ? 'selected' : '' ?>><?= __('COMMON_ACTIVE') ?></option>
                        <option value="0" <?= !$service['is_active'] ? 'selected' : '' ?>><?= __('COMMON_INACTIVE') ?></option>
                    </select>
                </div>
            </div>
            <div class="mt-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> <?= __('COMMON_SAVE') ?> Changes</button>
                <a href="/services/<?= (int)$service['id'] ?>" class="btn btn-outline-secondary"><?= __('COMMON_CANCEL') ?></a>
            </div>
        
                <!-- Слот в Форме А — управляется финансовой/коммерческой службой -->
                <div class="mb-3">
                    <label class="form-label fw-bold">
                        Строка в Форме А
                        <i class="bi bi-info-circle text-muted ms-1"
                           data-bs-toggle="tooltip"
                           title="Выберите в какой строке формы А будет отображаться эта услуга. Если оставить пустым — услуга будет в блоке «Прочие»."></i>
                    </label>
                    <?php
                    $slotGroups = [
                        'Airport services — основные' => [
                            'am_tlf'      => 'Взлёт/посадка Take off & Landing',
                            'am_av_sec'   => 'Авиационная безопасность',
                            'am_night'    => 'Ночное время',
                            'am_meteo'    => 'Метеообеспечение',
                            'am_fpl'      => 'Флайт-план',
                            'am_brief'    => 'Брифинг / предполётное инф.',
                            'am_pax_tax'  => 'Аэропортовые сборы PAX',
                            'am_fuel'     => 'Обеспечение ГСМ',
                            'am_deice_lq' => 'Спец жидкости ПО/Ж',
                        ],
                        'Airport services — дополнительные' => [
                            'aa_charter'   => 'Сбор за чартер',
                            'aa_ac_guard'  => 'Охрана ВС',
                            'aa_follow_me' => 'Сопровождение ВС / Follow me',
                            'aa_sec_pax'   => 'Сбор безопасности PAX вылет',
                            'aa_over_park' => 'Стоянка ВС / Parking',
                            'aa_tech_svc'  => 'Тех. обслуживание',
                            'aa_fire'      => 'Пожарная машина',
                        ],
                        'Handling — трап и спецтехника' => [
                            'ht_stairs_pax'  => 'Трап пассажирский',
                            'ht_stairs_tech' => 'Трап технический',
                            'ht_gpu'         => 'Утроник GPU',
                            'ht_acu'         => 'Кондиционер AC.U',
                        ],
                        'Handling — основные услуги' => [
                            'hm_pax_arr'  => 'Обслуживание PAX прилёт',
                            'hm_pax_dep'  => 'Обслуживание PAX вылет',
                            'hm_baggage'  => 'Обработка багажа',
                            'hm_bus_pax'  => 'Автобус для пассажиров',
                            'hm_bus_crew' => 'Автобус для экипажа',
                            'hm_ambulift' => 'Амбулифт / PRM',
                            'hm_ambulance'=> 'Машина скорой помощи',
                            'hm_pushback' => 'Буксировка ВС',
                            'hm_wb'       => 'Центровка / Weight & Balance',
                            'hm_load_ctl' => 'Контроль загрузки',
                            'hm_superv'   => 'Контроль обслуживания',
                            'hm_water'    => 'Заправка водой',
                            'hm_toilet'   => 'Туалет МА-7',
                            'hm_wchr'     => 'Инвалидное кресло WCHR',
                        ],
                        'Handling — дополнительные услуги' => [
                            'ha_stepladder' => 'Стремянка / Stepladders',
                            'ha_gse_tech'   => 'Техперсонал GSE',
                            'ha_marshalling'=> 'Маршаллинг / Рамп-хэндлинг',
                            'ha_flight_doc' => 'Рейсовая документация',
                            'ha_um_svc'     => 'Обслуживание UM',
                            'ha_dcs'        => 'Система DCS',
                            'ha_cute'       => 'Система CUTE / CUPPS',
                            'ha_air_start'  => 'Воздушный запуск двигателей',
                            'ha_o2'         => 'Заправка кислородом',
                            'ha_air_comp'   => 'Заправка сжатым воздухом',
                            'ha_wb_add'     => 'Дополнительная центровка',
                            'ha_deice_snw'  => 'Очистка от снега и льда',
                            'ha_deice_ctrl' => 'Контроль за удалением льда',
                            'ha_autolift'   => 'Предоставление автолифта',
                            'ha_crane'      => 'Автокран / лестница',
                        ],
                    ];
                    $currentSlot = $_POST['form_a_slot'] ?? ($service['form_a_slot'] ?? '');
                    ?>
                    <select name="form_a_slot" class="form-select">
                        <option value="">— Прочие / Other services (по умолчанию) —</option>
                        <?php foreach ($slotGroups as $groupName => $slots): ?>
                        <optgroup label="<?= htmlspecialchars($groupName) ?>">
                            <?php foreach ($slots as $value => $label): ?>
                            <option value="<?= $value ?>"
                                    <?= $currentSlot === $value ? 'selected' : '' ?>>
                                <?= htmlspecialchars($label) ?>
                            </option>
                            <?php endforeach; ?>
                        </optgroup>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text text-muted">
                        Если услуга не привязана к строке формы А — она будет в разделе «Прочие» со своей суммой.
                    </div>
                </div>

</form>
    </div>
</div>