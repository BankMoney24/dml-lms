## Functions

A **function** is a named piece of code that takes input and gives back a result. In JavaScript:

```js
function double(n) {
  return n * 2;
}

double(21);   // 42
```

- `n` is the **parameter**: the input.
- `return` sends the result back and ends the function at once.

## Making decisions with if / else if

```js
function describe(temperature) {
  if (temperature >= 30) {
    return "hot";
  } else if (temperature >= 20) {
    return "warm";
  } else {
    return "cool";
  }
}
```

The conditions are checked **from the top**. The first one that is true wins, so order matters: if `>= 20` came first, 35 would be called "warm".

## Comparison operators

| Operator | Means | Example that is true |
|---|---|---|
| `>=` | greater than or equal to | `70 >= 70` |
| `>` | greater than | `71 > 70` |
| `<=` | less than or equal to | `69 <= 69` |
| `<` | less than | `39 < 40` |
| `===` | equal to | `"A" === "A"` |

## Test the boundaries

Most mistakes hide at the edges. If a rule says "70 to 100 is an A", test 69, 70 and 100, not just 85. Using `>` where you meant `>=` gives the wrong answer for exactly one value, and that is the value examiners like to check.

> **Tip:** Text values need quotes: `return "A";`. Without them JavaScript looks for a variable called `A` and stops with an error.

## In the lab

You will complete a function `grade(score)` that turns an exam score into a WAEC-style letter grade. Press **Run the examples** as often as you like. When you check your answers, your function is run on a fresh set of scores, in a sandbox in your own browser that has no access to the internet or to this site.
