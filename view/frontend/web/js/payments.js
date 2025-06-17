const vindiVr = {
    copyCode: function(button, linkClass, onlyNumbers) {
        let str = document.querySelector(linkClass).innerText;
        if (onlyNumbers) {
            str = str.replace(/[^0-9]+/g, "");
        }
        const originalText = button.innerText;
        const originalIcon = button.querySelector('.vindi-copy-icon');
        
        // Criar elemento textarea para copiar
        const el = document.createElement('textarea');
        el.value = str;
        document.body.appendChild(el);
        el.select();
        document.execCommand('copy');
        document.body.removeChild(el);
        
        // Feedback visual melhorado
        button.classList.add('vindi-copied');
        if (originalIcon) {
            originalIcon.style.transform = 'scale(1.1)';
        }
        button.innerText = button.getAttribute('data-text');
        
        setTimeout(() => {
            button.classList.remove('vindi-copied');
            if (originalIcon) {
                originalIcon.style.transform = 'scale(1)';
            }
            button.innerText = originalText;
        }, 3000);
    }
};
