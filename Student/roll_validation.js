// Reusable roll number validation for student forms.
// Pattern: 2 digits + BK1A + 2 alphanumeric course chars + 2 alphanumeric roll chars.
const rollNumberPattern = /^[0-9]{2}BK1A[A-Za-z0-9]{2}[A-Za-z0-9]{2}$/;

/**
 * Check whether a student roll number is valid.
 * @param {string} rollNumber
 * @returns {boolean}
 */
function isValidRollNumber(rollNumber) {
    return rollNumberPattern.test(rollNumber.trim());
}

/**
 * Return an error message for invalid roll numbers.
 * @param {string} rollNumber
 * @returns {string}
 */
function getRollNumberErrorMessage(rollNumber) {
    if (!rollNumber || rollNumber.trim() === '') {
        return 'Roll number is required.';
    }

    if (rollNumber.trim().length !== 10) {
        return 'Roll number must be exactly 10 characters long.';
    }

    return 'Roll number must be in the format YYBK1ACCXX, for example 23BK1A66L5.';
}

/**
 * Attach roll number validation to a form.
 * @param {HTMLFormElement} form
 * @param {string} rollNumberFieldId
 * @param {string} errorContainerId
 */
function attachRollNumberValidation(form, rollNumberFieldId, errorContainerId) {
    const rollField = document.getElementById(rollNumberFieldId);
    const errorContainer = document.getElementById(errorContainerId);

    if (!form || !rollField || !errorContainer) {
        return;
    }

    form.addEventListener('submit', function (e) {
        const rollValue = rollField.value.trim();
        if (!isValidRollNumber(rollValue)) {
            e.preventDefault();
            errorContainer.textContent = getRollNumberErrorMessage(rollValue);
            errorContainer.style.display = 'block';
            rollField.classList.add('is-invalid');
            rollField.focus();
            return false;
        }

        errorContainer.style.display = 'none';
        rollField.classList.remove('is-invalid');
        return true;
    });
}
