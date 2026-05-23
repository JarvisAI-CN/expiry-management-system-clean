const video = document.getElementById('scanVideo');
const hint = document.getElementById('scanHint');
const rawCode = document.getElementById('rawCode');
const startScanButton = document.getElementById('startScan');
const stopScanButton = document.getElementById('stopScan');
const parseButton = document.getElementById('parseButton');
const scannerMessage = document.getElementById('scannerMessage');
const resultPanel = document.getElementById('resultPanel');
const saveForm = document.getElementById('saveForm');
const quantityInput = document.getElementById('quantity');
const noteInput = document.getElementById('note');
const toast = document.getElementById('toast');

let stream = null;
let detector = null;
let scanning = false;
let currentRawCode = '';

function setMessage(message, tone = '') {
    scannerMessage.textContent = message;
    scannerMessage.className = `inline-message ${tone}`.trim();
}

function showToast(message) {
    toast.textContent = message;
    toast.classList.add('visible');
    window.setTimeout(() => toast.classList.remove('visible'), 1800);
}

function formatDate(dateText) {
    if (!dateText) return '-';
    return dateText.replaceAll('-', '/');
}

function setStatusPill(status) {
    const pill = document.getElementById('expiryStatus');
    pill.textContent = status?.text || '待确认';
    pill.className = `status-pill ${status?.code || 'unknown'}`;
}

function fillResult(data) {
    const parsed = data.parsed;
    const product = data.product;
    currentRawCode = parsed.raw_code;

    document.getElementById('productName').textContent = product?.name || '未录入商品名';
    document.getElementById('skuText').textContent = parsed.sku || '-';
    document.getElementById('categoryText').textContent = product?.category || '-';
    document.getElementById('productionText').textContent = formatDate(parsed.production_date);
    document.getElementById('expiryText').textContent = formatDate(parsed.expiry_date);
    document.getElementById('daysLeftText').textContent =
        typeof parsed.days_left === 'number' ? `${parsed.days_left} 天` : '-';
    document.getElementById('alertDaysText').textContent =
        `${Number.parseInt(product?.alert_days ?? 7, 10)} 天内`;
    setStatusPill(data.status);

    resultPanel.classList.remove('is-hidden');
    quantityInput.value = '';
    noteInput.value = '';
    window.setTimeout(() => quantityInput.focus(), 120);
}

async function lookupCode(code) {
    const form = new FormData();
    form.append('code', code);

    const response = await fetch('api/lookup.php', {
        method: 'POST',
        body: form,
    });
    const data = await response.json();

    if (!data.ok) {
        setMessage(data.message || '没有识别成功', 'error');
        resultPanel.classList.add('is-hidden');
        return;
    }

    fillResult(data);
    setMessage(data.product ? '识别成功，填写数量即可保存。' : 'SKU 已识别，但后台还没有这个商品名。', data.product ? 'success' : 'warn');
    if ('vibrate' in navigator) {
        navigator.vibrate(80);
    }
}

async function startScanner() {
    if (!('BarcodeDetector' in window)) {
        setMessage('当前浏览器不支持直接识别二维码，可以把扫码结果粘贴到上面的输入框。', 'warn');
        hint.textContent = '可手动粘贴扫码结果';
        return;
    }

    try {
        detector = detector || new BarcodeDetector({ formats: ['qr_code'] });
        stream = await navigator.mediaDevices.getUserMedia({
            audio: false,
            video: { facingMode: { ideal: 'environment' } },
        });
        video.srcObject = stream;
        await video.play();
        scanning = true;
        hint.textContent = '对准二维码';
        scanLoop();
    } catch (error) {
        setMessage('摄像头没有打开，可以改用手动粘贴扫码结果。', 'error');
        hint.textContent = '摄像头未打开';
    }
}

function stopScanner() {
    scanning = false;
    if (stream) {
        stream.getTracks().forEach((track) => track.stop());
        stream = null;
    }
    video.srcObject = null;
    hint.textContent = '点击开始扫码';
}

async function scanLoop() {
    if (!scanning || !detector || !video.videoWidth) {
        if (scanning) requestAnimationFrame(scanLoop);
        return;
    }

    try {
        const codes = await detector.detect(video);
        if (codes.length > 0 && codes[0].rawValue) {
            const value = codes[0].rawValue.trim();
            stopScanner();
            rawCode.value = value;
            await lookupCode(value);
            return;
        }
    } catch (error) {
        setMessage('识别时遇到问题，请重新开始扫码。', 'error');
        stopScanner();
        return;
    }

    requestAnimationFrame(scanLoop);
}

startScanButton.addEventListener('click', startScanner);
stopScanButton.addEventListener('click', stopScanner);

parseButton.addEventListener('click', () => {
    const code = rawCode.value.trim();
    if (!code) {
        setMessage('请先扫码或粘贴二维码内容。', 'error');
        return;
    }
    lookupCode(code);
});

rawCode.addEventListener('change', () => {
    const code = rawCode.value.trim();
    if (code) lookupCode(code);
});

saveForm.addEventListener('submit', async (event) => {
    event.preventDefault();

    const quantity = Number.parseInt(quantityInput.value, 10);
    if (!currentRawCode || !quantity || quantity < 1) {
        showToast('请填写数量');
        return;
    }

    const response = await fetch('api/save.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            raw_code: currentRawCode,
            quantity,
            note: noteInput.value.trim(),
        }),
    });
    const data = await response.json();

    if (!data.ok) {
        showToast(data.message || '保存失败');
        return;
    }

    showToast('已保存');
    resultPanel.classList.add('is-hidden');
    rawCode.value = '';
    currentRawCode = '';
    quantityInput.value = '';
    noteInput.value = '';

    if (detector) {
        startScanner();
    }
});
