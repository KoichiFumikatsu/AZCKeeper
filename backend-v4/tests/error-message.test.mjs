import { test } from 'node:test';
import assert from 'node:assert/strict';
import { ApiError, errorMessage } from '../public/assets/api.js';

// Mensajes del panel con el errorMessage real (§7). El 403 de una regla por cargo trae en errors[] la persona y el rol.
const problem = (detail, errors) => new ApiError(403, { code: 'permission_denied', detail, request_id: 'req-1', ...(errors ? { errors } : {}) });

test('delegación sin detalle: mensaje general', () => {
  assert.equal(errorMessage(problem('delegation_exceeds_own_access')), 'No puedes dar un acceso más amplio que el tuyo. Referencia: req-1');
});
test('regla por cargo: nombra a la persona y el rol', () => {
  const message = 'No puedes dar el rol Gerente a Ana Pérez: tiene permisos que tú no tienes.';
  assert.equal(errorMessage(problem('delegation_exceeds_own_access', [{ pointer: '', code: 'delegation_exceeds_own_access', message }])), `${message} Referencia: req-1`);
});
test('errors de otro código no reemplazan el mensaje de delegación', () => {
  assert.equal(errorMessage(problem('delegation_exceeds_own_access', [{ pointer: '/x', code: 'otro', message: 'x' }])), 'No puedes dar un acceso más amplio que el tuyo. Referencia: req-1');
});
test('alcance y función siguen igual', () => {
  assert.equal(errorMessage(problem('scope_denied')), 'Tu alcance no incluye esa empresa, sede o área. Referencia: req-1');
  assert.equal(errorMessage(problem('feature_not_contracted:usb')), 'La función no está contratada por el cliente. Referencia: req-1');
});
