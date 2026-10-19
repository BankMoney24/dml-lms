## Why functions

A function gives a name to a piece of work, so you can use it again and test it on its own:

```python
def grade(score):
    if score >= 70:
        return "A"
    if score >= 60:
        return "B"
    return "C"

print(grade(85))   # A
print(grade(64))   # B
```

- `def` starts the function; `score` is its **parameter**.
- `return` hands a value back and ends the function at once.
- A function without `return` gives back `None`.

## print or return?

`print` shows something on the screen. `return` gives a value back to the code that called the function, so it can be stored, compared or tested. Tests check what a function **returns**.

## Several parameters

```python
def area(width, height):
    return width * height

print(area(3, 4))   # 12
```

## Practise

The [coding challenges](/playground/challenges) in the Code studio are short functions with tests: greetings, grades, FizzBuzz, palindromes, counting words and more. Each one runs its tests in your browser and tells you which pass. Try them in Python, then in JavaScript to see how the two languages compare.
