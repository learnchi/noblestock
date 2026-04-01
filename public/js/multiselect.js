/**
 * 使い方
 *     <select id="sampleSelect" name="sampleSelect[]" class="multiselect" multiple data-classes="w-50">
 *         <option value="0">オプション000</option>
 *         <option value="1">オプション001</option>
 *         <option value="2">オプション002</option>
 *     </select>
 * 
 * 受け付けているdata属性
 *     data-classes="w-50" 表示されるボタンへのクラス名
 *     data-textcontent="選択してください" 未選択時に表示されるボタン内容
 */
document.addEventListener('DOMContentLoaded', () => {

    function updateButtonCaption(select, button) {
        const selectedOptions = Array.from(select.selectedOptions);
        const count = selectedOptions.length;

        if (count === 0) {
            button.textContent = select.dataset.textcontent || '選択してください';
        } else if (count === 1) {
            button.textContent = selectedOptions[0].textContent;
        } else {
            button.textContent = `${count} 件選択`;
        }
    }

    // 複数 select をまとめて処理
    document.querySelectorAll('select.multiselect').forEach(select => {

        const classes = select.dataset.classes || '';

        const wrapper = document.createElement('div');
        wrapper.className = 'dropdown';

        const button = document.createElement('button');
        button.className = 'form-select form-select-sm text-start '+classes;
        button.type = 'button';
        button.setAttribute('data-bs-toggle', 'dropdown');
        button.textContent = '選択してください';

        const menu = document.createElement('ul');
        menu.className = 'dropdown-menu w-100';
        menu.style.maxHeight = '50vh';
        menu.style.overflowY = 'auto';

        Array.from(select.options).forEach(option => {
            const li = document.createElement('li');
            const label = document.createElement('label');
            label.className = 'dropdown-item d-flex align-items-center gap-2';

            const checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.className='form-check-input';
            checkbox.value = option.value;
            checkbox.checked = option.selected;

            checkbox.addEventListener('change', () => {
                option.selected = checkbox.checked;
                updateButtonCaption(select, button);
            });

            label.addEventListener('click', e => e.stopPropagation());

            label.appendChild(checkbox);
            label.append(option.textContent);
            li.appendChild(label);
            menu.appendChild(li);
        });

        wrapper.appendChild(button);
        updateButtonCaption(select, button);
        wrapper.appendChild(menu);

        // selectを隠して直前にUIを追加
        select.style.display = 'none';
        select.parentNode.insertBefore(wrapper, select);
    });
});



