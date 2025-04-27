function acceptricsFilterTextAreaContent() {
    const textarea = document.getElementById('acceptrics_custom_script');
    let content = textarea.value.trim();
    content = content.replace(/<script[^>]*?src=[\"'][^\"']*cdn\.acceptrics\.com[\"'\=\?\w]*>.*?<\/script>/gis, '');
    content = content.replace(/<\/?script>/gi, '').replace(/\\\\n/, '').trim();
    textarea.value = content;
}



