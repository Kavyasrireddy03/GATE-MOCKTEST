/**
 * calculator.js - Modular Scientific Calculator
 * Fixed for GATE Mock Test: Handles all scientific functions, 
 * binary operations, and virtual keyboard input.
 */

(function($) {
    $(document).ready(function() {
        const mainDisp = $('#calc_main');
        const histDisp = $('#calc_history');
        let memoryValue = 0;
        let newNumber = true;

        // 1. Initialize Draggable
        if ($.fn.draggable) {
            $("#calculatorPopup").draggable({ handle: "#keyPad_Header" });
        }
        
        // 2. UI Controls
        $('#calculatorBtn').on('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            $('#calculatorPopup').toggle();
        });

        $('#closeCalc').on('click', function(e) {
            e.preventDefault();
            $('#calculatorPopup').hide();
        });

        const getVal = () => {
            let v = mainDisp.val();
            if (v === "Error" || v === "NaN") return 0;
            return parseFloat(v) || 0;
        };
        
        const setVal = (v) => {
            if (isNaN(v) || !isFinite(v)) {
                mainDisp.val("Error");
            } else {
                // Precision 10 prevents floating point issues like 0.000000000004
                let res = Number(parseFloat(v).toPrecision(10));
                mainDisp.val(res);
            }
            newNumber = true; 
        };

        const factorial = (n) => {
            if (n < 0 || n > 170) return NaN;
            if (n === 0) return 1;
            let res = 1;
            for (let i = 2; i <= Math.floor(n); i++) res *= i;
            return res;
        };

        const isDeg = () => $('input[name="mode"]:checked').val() === "deg";
        const toRad = (v) => isDeg() ? v * (Math.PI / 180) : v;
        const fromRad = (v) => isDeg() ? v * (180 / Math.PI) : v;

        // 3. Numeric & Bracket Inputs (Fixed delegation)
        $(document).on('click', '#calculatorPopup .num, #calculatorPopup a:contains("("), #calculatorPopup a:contains(")")', function(e) {
            e.preventDefault();
            let char = $(this).text().trim();
            let current = mainDisp.val();

            if (newNumber && char !== "(" && char !== ")") {
                mainDisp.val(char === "." ? "0." : char);
                newNumber = false;
            } else {
                if (current === "0" && char !== "." && char !== "(" && char !== ")") current = "";
                if (current === "Error") current = "";
                mainDisp.val(current + char);
                newNumber = false;
            }
        });

        // 4. Constants
        $(document).on('click', '#calculatorPopup .const', function(e) {
            e.preventDefault();
            let val = $(this).text().trim() === 'π' ? Math.PI : Math.E;
            setVal(val);
        });

        // 5. Binary Operators (+, -, *, /, mod, Exp, xʸ, ʸ√x)
        $(document).on('click', '#calculatorPopup .op-binary', function(e) {
            e.preventDefault();
            let op = $(this).text().trim();
            let current = mainDisp.val();
            if (current === "Error") current = "0";
            
            // Map visual button text to logic operators
            let opMap = { "mod": " mod ", "Exp": "e+", "xʸ": "^", "ʸ√x": "yroot" };
            let displayOp = opMap[op] || " " + op + " ";
            
            mainDisp.val(current + displayOp);
            newNumber = false;
        });

        // 6. Unary Operators (sin, log, √, etc.)
        $(document).on('click', '#calculatorPopup .op-unary', function(e) {
            e.preventDefault();
            let func = $(this).text().trim();
            let v = getVal();
            let res = 0;

            try {
                switch(func) {
                    case 'sin': res = Math.sin(toRad(v)); break;
                    case 'cos': res = Math.cos(toRad(v)); break;
                    case 'tan': res = Math.tan(toRad(v)); break;
                    case 'sin⁻¹': res = fromRad(Math.asin(v)); break;
                    case 'cos⁻¹': res = fromRad(Math.acos(v)); break;
                    case 'tan⁻¹': res = fromRad(Math.atan(v)); break;
                    case 'sinh': res = Math.sinh(v); break;
                    case 'cosh': res = Math.cosh(v); break;
                    case 'tanh': res = Math.tanh(v); break;
                    case 'log': res = Math.log10(v); break;
                    case 'ln': res = Math.log(v); break;
                    case 'log₂x': res = Math.log2(v); break;
                    case 'eˣ': res = Math.exp(v); break;
                    case '10ˣ': res = Math.pow(10, v); break;
                    case 'x²': res = Math.pow(v, 2); break;
                    case 'x³': res = Math.pow(v, 3); break;
                    case '³√': res = Math.cbrt(v); break;
                    case '√': res = Math.sqrt(v); break;
                    case '|x|': res = Math.abs(v); break;
                    case 'n!': res = factorial(v); break;
                    default: res = v;
                }
                histDisp.val(func + "(" + v + ")");
                setVal(res);
            } catch(err) { mainDisp.val("Error"); }
        });

        // 7. Equals Logic (=) - The most important part
        $(document).on('click', '#btn_enter', function(e) {
            e.preventDefault();
            let expr = mainDisp.val();
            if (!expr || expr === "Error") return;

            try {
                // Replace visual characters with JS Math logic
                let processed = expr.replace(/π/g, Math.PI)
                                    .replace(/e/g, Math.E)
                                    .replace(/mod/g, '%')
                                    .replace(/\^/g, '**');

                // Handle yroot (Example: 8yroot3 becomes Math.pow(8, 1/3))
                if (processed.includes('yroot')) {
                    let parts = processed.split('yroot');
                    processed = "Math.pow(" + parts[0] + ", 1/" + parts[1] + ")";
                }

                // Auto-close open brackets
                let openBrackets = (processed.match(/\(/g) || []).length;
                let closeBrackets = (processed.match(/\)/g) || []).length;
                while (openBrackets > closeBrackets) {
                    processed += ')';
                    closeBrackets++;
                }

                // Evaluate the string safely
                let result = Function('"use strict";return (' + processed + ')')();
                histDisp.val(expr + " =");
                setVal(result);
            } catch (err) {
                console.error("Calculation Error", err);
                mainDisp.val("Error");
                newNumber = true;
            }
        });

        // 8. Control Keys
        $(document).on('click', '#btn_clear', function(e) {
            e.preventDefault();
            mainDisp.val("0"); histDisp.val(""); newNumber = true;
        });

        $(document).on('click', '#btn_back', function(e) {
            e.preventDefault();
            let s = mainDisp.val();
            mainDisp.val(s.length > 1 ? s.substring(0, s.length - 1) : "0");
        });

        $(document).on('click', '#btn_inv', function(e) {
            e.preventDefault();
            setVal(getVal() * -1);
        });

        // 9. Memory Functions
        $(document).on('click', '#calculatorPopup .mem-func', function(e) {
            e.preventDefault();
            let type = $(this).text().trim();
            if (type === 'MS') memoryValue = getVal();
            else if (type === 'MC') memoryValue = 0;
            else if (type === 'MR') { setVal(memoryValue); return; }
            else if (type === 'M+') memoryValue += getVal();
            else if (type === 'M-') memoryValue -= getVal();
            $('#mem_sym').css('visibility', memoryValue !== 0 ? 'visible' : 'hidden');
            newNumber = true;
        });
    });
})(jQuery);