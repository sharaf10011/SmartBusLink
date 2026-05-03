// Wait for the document to be fully loaded
document.addEventListener("DOMContentLoaded", function () {
    // Form Validation
    validateForms();

    // Scroll to Top Button
    scrollToTop();

    // Confirmation for Delete Actions
    setupDeleteConfirmation();
});



/**
 * Function to validate forms before submission
 */
function validateForms() {
    const forms = document.querySelectorAll("form");

    forms.forEach((form) => {
        form.addEventListener("submit", function (event) {
            const inputs = form.querySelectorAll("input[required], textarea[required], select[required]");
            let isValid = true;

            inputs.forEach((input) => {
                if (!input.value.trim()) {
                    input.classList.add("error-input");
                    isValid = false;
                } else {
                    input.classList.remove("error-input");
                }
            });

            if (!isValid) {
                event.preventDefault();
                alert("Please fill out all required fields.");
            }
        });
    });
}

/**
 * Function for confirmation prompts on delete actions
 */
function setupDeleteConfirmation() {
    const deleteButtons = document.querySelectorAll(".btn-delete");

    deleteButtons.forEach((button) => {
        button.addEventListener("click", function (event) {
            const confirmation = confirm("Are you sure you want to delete this entry?");
            if (!confirmation) {
                event.preventDefault();
            }
        });
    });
}


/**
 * Scroll-to-top button functionality
 */
function scrollToTop() {
    const scrollButton = document.createElement("button");
    scrollButton.textContent = "↑";
    scrollButton.classList.add("scroll-to-top");
    document.body.appendChild(scrollButton);

    window.addEventListener("scroll", () => {
        if (window.scrollY > 200) {
            scrollButton.classList.add("show");
        } else {
            scrollButton.classList.remove("show");
        }
    });

    scrollButton.addEventListener("click", () => {
        window.scrollTo({
            top: 0,
            behavior: "smooth"
        });
    });
}


// Back to Top Button
window.addEventListener('scroll', function() {
    var backToTop = document.querySelector('.back-to-top');
    if (window.pageYOffset > 300) {
        backToTop.classList.add('show');
    } else {
        backToTop.classList.remove('show');
    }
});

// Smooth scroll for back to top
document.querySelector('.back-to-top').addEventListener('click', function(e) {
    e.preventDefault();
    window.scrollTo({
        top: 0,
        behavior: 'smooth'
    });
});


// Add to your main.js
document.addEventListener('DOMContentLoaded', function() {
    // Close navbar when clicking on dropdown items on mobile
    const navLinks = document.querySelectorAll('.nav-link, .dropdown-item');
    const navbarToggler = document.querySelector('.navbar-toggler');
    const navbarCollapse = document.querySelector('.navbar-collapse');
    
    navLinks.forEach(link => {
        link.addEventListener('click', () => {
            if (navbarCollapse.classList.contains('show')) {
                navbarToggler.click();
            }
        });
    });
});





