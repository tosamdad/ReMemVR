/**
 * RMRecorder: 마이크 녹음 → WAV(PCM 16bit, 모노) + 품질 지표 + 간단한 음성 감지(VAD).
 * 목소리 연구실(가족 목소리 샘플 녹음)과 플레이어(아이 질문 녹음)가 함께 쓴다.
 * WAV 로 만드는 이유: 브라우저마다 MediaRecorder 형식이 달라(webm, mp4) Gemini, ElevenLabs 가 모두 받는 형식으로 통일한다.
 *
 *   var rec = new RMRecorder({
 *     sampleRate: 24000,            // 저장 표본율(샘플 녹음 24000, 질문 16000 권장)
 *     maxMs: 60000,                 // 최대 녹음 길이. 도달하면 자동 정지(reason: 'max')
 *     vad: { minSpeechMs: 1000, silenceStopMs: 1200, noSpeechTimeoutMs: 6000 },  // 없으면 자동 정지 없음
 *     echoCancellation: true, noiseSuppression: false, autoGainControl: false,
 *     onLevel: function (db, isSpeech) {},   // 약 50~90ms 마다 현재 음량(dBFS)
 *     onTick: function (elapsedMs) {},
 *     onAutoStop: function (reason) {}      // 'silence' | 'no_speech' | 'max'  (이후 stop() 을 불러 결과를 받는다)
 *   });
 *   await rec.start();                 // 마이크 권한 요청. 실패하면 Error(한글 메시지)
 *   var r = await rec.stop();          // { blob, durationMs, sampleRate, metrics, reason }
 *   rec.cancel();                      // 결과 없이 정리
 *   RMRecorder.supported()             // 녹음 가능 여부
 *   await RMRecorder.analyzeFile(file) // 업로드 파일 품질 측정 { durationMs, metrics } (디코딩 못 하면 metrics: null)
 *
 * metrics: { peakDb, rmsDb, noiseDb, snrDb, clipCount, speechMs, grade: 'good' | 'fair' | 'poor' }
 */
(function () {
  'use strict';

  var AC = window.AudioContext || window.webkitAudioContext;

  function RMRecorder(opts) {
    this.opts = Object.assign({
      sampleRate: 24000,
      maxMs: 60000,
      vad: null,
      echoCancellation: true,
      noiseSuppression: false,
      autoGainControl: false,
      onLevel: null,
      onTick: null,
      onAutoStop: null
    }, opts || {});
    this.chunks = [];
    this.length = 0;
    this.running = false;
    this.reason = 'manual';
  }

  RMRecorder.supported = function () {
    return !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia && AC);
  };

  RMRecorder.prototype.start = async function () {
    if (!RMRecorder.supported()) {
      throw new Error('이 브라우저에서는 녹음을 사용할 수 없습니다. 최신 Chrome 또는 Safari 를 사용해 주세요.');
    }
    if (!window.isSecureContext && location.hostname !== 'localhost' && location.hostname !== '127.0.0.1') {
      throw new Error('녹음은 https 주소에서만 사용할 수 있습니다.');
    }
    var o = this.opts;
    try {
      this.stream = await navigator.mediaDevices.getUserMedia({
        audio: { channelCount: 1, echoCancellation: o.echoCancellation, noiseSuppression: o.noiseSuppression, autoGainControl: o.autoGainControl }
      });
    } catch (e) {
      if (e && (e.name === 'NotAllowedError' || e.name === 'SecurityError')) {
        throw new Error('마이크 사용 권한이 필요합니다. 브라우저 설정에서 마이크를 허용해 주세요.');
      }
      if (e && e.name === 'NotFoundError') throw new Error('사용할 수 있는 마이크가 없습니다.');
      throw new Error('마이크를 시작하지 못했습니다.');
    }
    this.ctx = new AC();
    if (this.ctx.state === 'suspended') { try { await this.ctx.resume(); } catch (e) {} }
    this.srcRate = this.ctx.sampleRate;
    this.source = this.ctx.createMediaStreamSource(this.stream);
    this.proc = this.ctx.createScriptProcessor(4096, 1, 1);
    this.chunks = [];
    this.length = 0;
    this.startedAt = performance.now();
    this.running = true;
    this.reason = 'manual';

    // 음성 감지 상태
    this.noiseFloor = null;
    this.speechMs = 0;
    this.silenceMs = 0;
    this.heardSpeech = false;

    var self = this;
    this.proc.onaudioprocess = function (e) {
      if (!self.running) return;
      var input = e.inputBuffer.getChannelData(0);
      var copy = new Float32Array(input.length);
      copy.set(input);
      self.chunks.push(copy);
      self.length += copy.length;
      self._onFrame(copy);
    };
    this.source.connect(this.proc);
    this.proc.connect(this.ctx.destination);
  };

  RMRecorder.prototype._onFrame = function (frame) {
    var o = this.opts;
    var sum = 0;
    for (var i = 0; i < frame.length; i++) sum += frame[i] * frame[i];
    var rms = Math.sqrt(sum / frame.length);
    var db = rms > 0 ? 20 * Math.log10(rms) : -100;
    var frameMs = frame.length / this.srcRate * 1000;
    var elapsed = this.length / this.srcRate * 1000;

    // 소음 바닥: 처음 값으로 시작해 조용한 구간에서 천천히 따라간다.
    if (this.noiseFloor === null) this.noiseFloor = Math.min(db, -45);
    else if (db < this.noiseFloor + 3) this.noiseFloor = this.noiseFloor * 0.9 + db * 0.1;
    var isSpeech = db > Math.max(this.noiseFloor + 10, -50);

    if (o.onLevel) o.onLevel(db, isSpeech);
    if (o.onTick) o.onTick(elapsed);

    if (elapsed >= o.maxMs) { this._auto('max'); return; }
    if (!o.vad) return;
    if (isSpeech) {
      this.speechMs += frameMs;
      this.silenceMs = 0;
      if (this.speechMs >= (o.vad.minSpeechMs || 1000)) this.heardSpeech = true;
    } else {
      this.silenceMs += frameMs;
    }
    if (this.heardSpeech && this.silenceMs >= (o.vad.silenceStopMs || 1200)) { this._auto('silence'); return; }
    if (!this.heardSpeech && elapsed >= (o.vad.noSpeechTimeoutMs || 6000)) { this._auto('no_speech'); }
  };

  RMRecorder.prototype._auto = function (reason) {
    if (this.autoFired) return;
    this.autoFired = true;
    this.reason = reason;
    if (this.opts.onAutoStop) this.opts.onAutoStop(reason);
  };

  RMRecorder.prototype._teardown = function () {
    this.running = false;
    try { this.proc && this.proc.disconnect(); } catch (e) {}
    try { this.source && this.source.disconnect(); } catch (e) {}
    if (this.stream) this.stream.getTracks().forEach(function (t) { t.stop(); });
    if (this.ctx && this.ctx.state !== 'closed') { try { this.ctx.close(); } catch (e) {} }
  };

  RMRecorder.prototype.stop = async function () {
    this._teardown();
    var merged = new Float32Array(this.length);
    var off = 0;
    this.chunks.forEach(function (c) { merged.set(c, off); off += c.length; });
    this.chunks = [];
    var rate = Math.min(this.opts.sampleRate, this.srcRate);
    var samples = resample(merged, this.srcRate, rate);
    var metrics = analyze(samples, rate);
    var durationMs = Math.round(samples.length / rate * 1000);
    return { blob: encodeWav(samples, rate), durationMs: durationMs, sampleRate: rate, metrics: metrics, reason: this.reason, heardSpeech: !!this.heardSpeech || metrics.speechMs >= 400 };
  };

  RMRecorder.prototype.cancel = function () {
    this._teardown();
    this.chunks = [];
    this.length = 0;
  };

  RMRecorder.analyzeFile = async function (file) {
    if (!AC) return { durationMs: null, metrics: null };
    var ctx = new AC();
    try {
      var buf = await file.arrayBuffer();
      var audio = await new Promise(function (resolve, reject) {
        var p = ctx.decodeAudioData(buf, resolve, reject);
        if (p && p.then) p.then(resolve, reject);
      });
      var data = audio.getChannelData(0);
      return { durationMs: Math.round(audio.duration * 1000), metrics: analyze(data, audio.sampleRate) };
    } catch (e) {
      return { durationMs: null, metrics: null };
    } finally {
      try { ctx.close(); } catch (e) {}
    }
  };

  /** 표본율 낮추기(구간 평균, 간단한 저역 통과 효과) */
  function resample(input, from, to) {
    if (from === to) return input;
    var ratio = from / to;
    var outLen = Math.floor(input.length / ratio);
    var out = new Float32Array(outLen);
    for (var i = 0; i < outLen; i++) {
      var start = Math.floor(i * ratio), end = Math.min(input.length, Math.floor((i + 1) * ratio));
      var sum = 0, n = 0;
      for (var j = start; j < end; j++) { sum += input[j]; n++; }
      out[i] = n ? sum / n : input[start] || 0;
    }
    return out;
  }

  /** 20ms 구간별 음량으로 소음, 신호 대 잡음비(SNR), 클리핑, 말한 시간을 잰다. */
  function analyze(samples, rate) {
    var frame = Math.max(1, Math.round(rate * 0.02));
    var dbs = [];
    var peak = 0, clip = 0, total = 0;
    for (var i = 0; i < samples.length; i += frame) {
      var sum = 0, end = Math.min(samples.length, i + frame);
      for (var j = i; j < end; j++) {
        var v = samples[j], a = Math.abs(v);
        sum += v * v;
        if (a > peak) peak = a;
        if (a >= 0.98) clip++;
      }
      total += sum;
      var rms = Math.sqrt(sum / Math.max(1, end - i));
      dbs.push(rms > 0 ? 20 * Math.log10(rms) : -100);
    }
    if (!dbs.length) return { peakDb: -100, rmsDb: -100, noiseDb: -100, snrDb: 0, clipCount: 0, speechMs: 0, grade: 'poor' };
    var sorted = dbs.slice().sort(function (a, b) { return a - b; });
    var pct = function (p) { return sorted[Math.min(sorted.length - 1, Math.floor(sorted.length * p))]; };
    var noise = pct(0.1), speech = pct(0.9);
    var speechFrames = dbs.filter(function (d) { return d > Math.max(noise + 10, -50); }).length;
    var snr = Math.max(0, speech - noise);
    var overall = Math.sqrt(total / Math.max(1, samples.length));
    var clipRatio = clip / Math.max(1, samples.length);
    var grade = (snr >= 25 && clipRatio < 0.001 && speech > -40) ? 'good' : (snr >= 15 && clipRatio < 0.01 && speech > -50) ? 'fair' : 'poor';
    return {
      peakDb: round1(peak > 0 ? 20 * Math.log10(peak) : -100),
      rmsDb: round1(overall > 0 ? 20 * Math.log10(overall) : -100),
      noiseDb: round1(noise),
      snrDb: round1(snr),
      clipCount: clip,
      speechMs: speechFrames * 20,
      grade: grade
    };
  }

  function round1(v) { return Math.round(v * 10) / 10; }

  function encodeWav(samples, rate) {
    var buffer = new ArrayBuffer(44 + samples.length * 2);
    var view = new DataView(buffer);
    function str(off, s) { for (var i = 0; i < s.length; i++) view.setUint8(off + i, s.charCodeAt(i)); }
    str(0, 'RIFF'); view.setUint32(4, 36 + samples.length * 2, true); str(8, 'WAVE');
    str(12, 'fmt '); view.setUint32(16, 16, true); view.setUint16(20, 1, true); view.setUint16(22, 1, true);
    view.setUint32(24, rate, true); view.setUint32(28, rate * 2, true); view.setUint16(32, 2, true); view.setUint16(34, 16, true);
    str(36, 'data'); view.setUint32(40, samples.length * 2, true);
    var off = 44;
    for (var i = 0; i < samples.length; i++, off += 2) {
      var s = Math.max(-1, Math.min(1, samples[i]));
      view.setInt16(off, s < 0 ? s * 0x8000 : s * 0x7fff, true);
    }
    return new Blob([view], { type: 'audio/wav' });
  }

  RMRecorder.analyze = analyze;
  RMRecorder.encodeWav = encodeWav;
  window.RMRecorder = RMRecorder;
})();
