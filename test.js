import http from 'k6/http';
import { check } from 'k6';
import exec from 'k6/execution';
import { Rate } from 'k6/metrics';

const BASE_URL = __ENV.BASE_URL || 'http://localhost/AZCKeeper/Web/public/index.php/api/client/handshake';
const TOKEN = __ENV.TOKEN || '';
const CLIENTS = parseInt(__ENV.CLIENTS || '300', 10);
const HANDSHAKE_INTERVAL_S = parseInt(__ENV.HANDSHAKE_INTERVAL_S || '300', 10);
const MODE = (__ENV.MODE || 'steady').toLowerCase();
const DURATION = __ENV.DURATION || '5m';
const BURST_RPS = parseInt(__ENV.BURST_RPS || '25', 10);
const DEVICE_MODE = (__ENV.DEVICE_MODE || 'single').toLowerCase();
const FIXED_DEVICE_ID = __ENV.DEVICE_ID || 'c7d15e48-06d3-40c4-9e64-0fbce19e213a';
const FIXED_DEVICE_NAME = __ENV.DEVICE_NAME || 'k6-local-client';
const DEBUG_FAILURES = (__ENV.DEBUG_FAILURES || 'true').toLowerCase() === 'true';

const non200Rate = new Rate('non_200_rate');
const logicalFailureRate = new Rate('logical_failure_rate');

function guidFor(index) {
  const hex = String(index).padStart(12, '0');
  return `00000000-0000-4000-8000-${hex}`;
}

function safeRatePerSecond() {
  return Math.max(1, Math.ceil(CLIENTS / HANDSHAKE_INTERVAL_S));
}

function buildOptions() {
  if (MODE === 'burst') {
    return {
      scenarios: {
        burst_guardrail: {
          executor: 'constant-arrival-rate',
          rate: BURST_RPS,
          timeUnit: '1s',
          duration: DURATION,
          preAllocatedVUs: Math.max(10, BURST_RPS),
          maxVUs: Math.max(20, BURST_RPS * 2),
        },
      },
      thresholds: {
        http_req_failed: ['rate<0.01'],
        http_req_duration: ['p(95)<800', 'p(99)<1500'],
        non_200_rate: ['rate<0.01'],
        logical_failure_rate: ['rate<0.01'],
      },
    };
  }

  const rate = safeRatePerSecond();
  return {
    scenarios: {
      steady_safe_rate: {
        executor: 'constant-arrival-rate',
        rate,
        timeUnit: '1s',
        duration: DURATION,
        preAllocatedVUs: Math.max(5, rate * 2),
        maxVUs: Math.max(10, rate * 4),
      },
    },
    thresholds: {
      http_req_failed: ['rate<0.01'],
      http_req_duration: ['p(95)<500', 'p(99)<1000'],
      non_200_rate: ['rate<0.01'],
      logical_failure_rate: ['rate<0.01'],
    },
  };
}

export const options = buildOptions();

export default function () {
  if (!TOKEN) {
    throw new Error('Define TOKEN para probar el handshake real. Ejemplo: k6 run -e TOKEN=xxxxx test.js');
  }

  const clientIndex = exec.scenario.iterationInTest % CLIENTS;
  const useFixedDevice = DEVICE_MODE !== 'pool';
  const deviceId = useFixedDevice ? FIXED_DEVICE_ID : guidFor(clientIndex);
  const deviceName = useFixedDevice ? FIXED_DEVICE_NAME : `k6-client-${clientIndex}`;
  const payload = JSON.stringify({
    version: '1.0.0.0',
    deviceId,
    deviceName,
  });

  const res = http.post(BASE_URL, payload, {
    headers: {
      Authorization: `Bearer ${TOKEN}`,
      'Content-Type': 'application/json',
    },
    tags: {
      mode: MODE,
    },
  });

  const ok = check(res, {
    'status is 200': (r) => r.status === 200,
    'body has ok=true': (r) => {
      try {
        return JSON.parse(r.body || '{}').ok === true;
      } catch (_) {
        return false;
      }
    },
  });

  non200Rate.add(res.status !== 200);
  logicalFailureRate.add(!ok);

  if (!ok && DEBUG_FAILURES) {
    const bodyPreview = (res.body || '').slice(0, 300).replace(/\s+/g, ' ');
    console.error(`handshake failure status=${res.status} mode=${DEVICE_MODE} body=${bodyPreview}`);
  }
}
