<?php
/**
 * The printable credential slips: one per person, laid out two-up for cutting.
 *
 * Shared by every path that hands out a generated password - the bulk student
 * import, a single password reset, and a whole-class reset. One template, so a
 * slip printed after a reset is indistinguishable from one printed after an
 * import and a change to the layout cannot land on only half of them.
 *
 * Presentation only. It reads state and never changes it, and it does not clear
 * the plaintext it is handed: that is the caller's job, done before rendering.
 *
 * Set this before requiring it:
 *
 *   $credentials   required. A list of ['full_name', 'admission_no',
 *                  'class_label', 'password']. class_label and admission_no may
 *                  be empty for a member of staff, who has neither.
 *
 * $credentials is cleared afterwards so a second slip block on the same request
 * cannot inherit the previous set.
 */
?>
<div class="slips">
    <?php foreach ($credentials as $slipRow): ?>
    <div class="slip">
        <div class="slip__school"><?= APP_NAME ?></div>

        <div class="slip__name"><?= htmlspecialchars($slipRow['full_name']) ?></div>

        <?php if (($slipRow['class_label'] ?? '') !== ''): ?>
            <div class="slip__class"><?= htmlspecialchars($slipRow['class_label']) ?></div>
        <?php endif; ?>

        <dl class="slip__creds">
            <?php if (($slipRow['admission_no'] ?? '') !== ''): ?>
                <dt>Admission no.</dt>
                <dd class="slip__mono"><?= htmlspecialchars($slipRow['admission_no']) ?></dd>
            <?php else: ?>
                <!-- Staff sign in with an email, which they already know; the
                     slip carries only the part they cannot be expected to. -->
                <dt>Sign in with</dt>
                <dd class="slip__mono"><?= htmlspecialchars($slipRow['email'] ?? 'your email address') ?></dd>
            <?php endif; ?>

            <dt>Password</dt>
            <dd class="slip__mono slip__password"><?= htmlspecialchars($slipRow['password']) ?></dd>
        </dl>

        <div class="slip__foot">
            <?php if (($slipRow['admission_no'] ?? '') !== ''): ?>
                Sign in with your admission number. Keep this slip safe.
            <?php else: ?>
                Keep this slip safe.
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php
unset($credentials, $slipRow);
