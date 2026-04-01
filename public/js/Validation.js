/**
 * JavaScript ユーティリティ custom Bootstrap validation
 * Validation.js
 * submit時に、HTMLに記載されたルールに従い、バリデーションチェックをして、結果を表示します。
 * 
 * 使い方
 * <form method="post" action="XXX.php" class="needs-validation" novalidate>
 * 
 * <input type="text" class="form-control" name="management_no" maxlength="18" required>
 * <div class="invalid-feedback">必須項目です。</div>
 * 
 * @author Studio GAU
 * @version 1.0
 */


document.addEventListener('DOMContentLoaded', () => {

    // バリデーション対象のformを全て取得
    const forms = document.querySelectorAll('.needs-validation')

    Array.from(forms).forEach(form => {
        form.addEventListener('submit', event => {

        // 押されたボタンを取得
        const submitter = event.submitter;

        // validationする場合のボタンのvalue
        if (submitter && (submitter.value === 'confirm' || submitter.value === 'update')) {
            if (!form.checkValidity()) {
                event.preventDefault()
                event.stopPropagation()
            }
            form.classList.add('was-validated');
        }


        }, false)
    })

});
