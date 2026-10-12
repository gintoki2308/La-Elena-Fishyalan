<?php
if (!isset($fields,$prefix,$bases) || !isLoggedIn() || !isAdmin()) { http_response_code(403); exit; }
?>
<div class="col-md-6"><label class="form-label" for="<?= $prefix ?>Basis">Pay basis</label><select id="<?= $prefix ?>Basis" name="pay_basis" class="form-select" required><?php foreach ($bases as $key=>$label): ?><option value="<?= $key ?>" <?= $fields['pay_basis']===$key?'selected':'' ?>><?= $label ?></option><?php endforeach ?></select></div>
<div class="col-md-6"><label class="form-label" for="<?= $prefix ?>Rate">Salary / rate (PHP)</label><input id="<?= $prefix ?>Rate" type="number" name="rate" min="0.01" max="9999999999.99" step="0.01" value="<?= $e($fields['rate']) ?>" class="form-control" required></div>
<?php foreach (['allowances'=>'Allowances / bonuses (PHP)','deductions'=>'Deductions (PHP)'] as $key=>$label): ?><div class="col-md-6"><label class="form-label" for="<?= $prefix.$key ?>"><?= $label ?></label><input id="<?= $prefix.$key ?>" type="number" name="<?= $key ?>" min="0" max="9999999999.99" step="0.01" value="<?= $e($fields[$key]) ?>" class="form-control" required></div><?php endforeach ?>
<div class="col-12"><label class="form-label" for="<?= $prefix ?>Notes">Payroll notes / adjustment reasons</label><textarea id="<?= $prefix ?>Notes" name="notes" maxlength="1000" rows="3" class="form-control"><?= $e($fields['notes']) ?></textarea></div>
