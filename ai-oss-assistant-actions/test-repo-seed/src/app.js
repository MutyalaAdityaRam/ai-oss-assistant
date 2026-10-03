// Seeded test file for tool verification
const AWS_SECRET_KEY = "AKIAIOSFODNN7EXAMPLE"; // Gitleaks secret pattern

function handleUserInput(input) {
  // Semgrep & CodeQL pattern: unsafe eval call
  return eval(input);
}

function processUserEvent(target, event) {
  // OpenHands fix target: missing null guard
  target.dispatch(event);
}

module.exports = { handleUserInput, processUserEvent };
