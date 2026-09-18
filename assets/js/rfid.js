/**
 * FILE       : assets/js/rfid.js
 * MODUL      : Integrasi Perangkat Keras RFID & Barcode
 * FUNGSI     : Menangani pemindaian otomatis kartu RFID USB dan barcode scanner (Keyboard Emulation Mode)
 *
 * TANGGUNG JAWAB:
 * - Mengarahkan fokus kursor otomatis ke elemen dengan class `.rfid-autofocus`.
 * - Menangkap tombol Enter yang dikirimkan pemindai RFID/barcode pada elemen `input[data-rfid-scanner="true"]`.
 * - Memutar audio konfirmasi nada beep sintetis (Web Audio API) saat data kartu berhasil masuk.
 * - Melakukan auto-submit form jika atribut `data-auto-submit="true"` disematkan.
 */
const SIPALASound = {
    ctx: null,
    init() {
        if (!this.ctx && (window.AudioContext || window.webkitAudioContext)) {
            const AudioCtx = window.AudioContext || window.webkitAudioContext;
            this.ctx = new AudioCtx();
        }
    },
    beepSuccess() {
        try {
            this.init();
            if (!this.ctx) return;
            if (this.ctx.state === 'suspended') this.ctx.resume();
            
            const now = this.ctx.currentTime;
            const osc1 = this.ctx.createOscillator();
            const gain1 = this.ctx.createGain();
            osc1.type = 'sine';
            osc1.frequency.setValueAtTime(880, now);
            gain1.gain.setValueAtTime(0.15, now);
            gain1.gain.exponentialRampToValueAtTime(0.001, now + 0.12);
            osc1.connect(gain1);
            gain1.connect(this.ctx.destination);
            osc1.start(now);
            osc1.stop(now + 0.12);

            const osc2 = this.ctx.createOscillator();
            const gain2 = this.ctx.createGain();
            osc2.type = 'sine';
            osc2.frequency.setValueAtTime(1320, now + 0.08);
            gain2.gain.setValueAtTime(0.2, now + 0.08);
            gain2.gain.exponentialRampToValueAtTime(0.001, now + 0.25);
            osc2.connect(gain2);
            gain2.connect(this.ctx.destination);
            osc2.start(now + 0.08);
            osc2.stop(now + 0.25);
        } catch (e) {}
    },
    beepError() {
        try {
            this.init();
            if (!this.ctx) return;
            if (this.ctx.state === 'suspended') this.ctx.resume();
            const now = this.ctx.currentTime;
            const osc = this.ctx.createOscillator();
            const gain = this.ctx.createGain();
            osc.type = 'sawtooth';
            osc.frequency.setValueAtTime(220, now);
            gain.gain.setValueAtTime(0.25, now);
            gain.gain.exponentialRampToValueAtTime(0.001, now + 0.3);
            osc.connect(gain);
            gain.connect(this.ctx.destination);
            osc.start(now);
            osc.stop(now + 0.3);
        } catch (e) {}
    }
};

window.SIPALASound = SIPALASound;

document.addEventListener('DOMContentLoaded', () => {
    // Auto focus reader input jika ada
    const autoFocusInputs = document.querySelectorAll('.rfid-autofocus');
    if (autoFocusInputs.length > 0) {
        setTimeout(() => autoFocusInputs[0].focus(), 200);
    }

    // Tangani input rfid dengan atribut data-auto-submit
    const rfidInputs = document.querySelectorAll('input[data-rfid-scanner="true"]');
    rfidInputs.forEach(input => {
        input.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                const val = input.value.trim();
                if (val.length > 0) {
                    SIPALASound.beepSuccess();
                    const form = input.closest('form');
                    if (input.dataset.autoSubmit === 'true' && form) {
                        form.submit();
                    } else if (input.dataset.triggerScan) {
                        const btn = document.getElementById(input.dataset.triggerScan);
                        if (btn) btn.click();
                    }
                }
            }
        });
    });
});
