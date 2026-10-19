## Your first program

Open the [Code studio](/playground?lang=python), choose **Python**, and run this:

```python
print("Hello, world!")
```

`print()` shows whatever you give it. Text goes in quotes; numbers do not.

## Variables

A variable is a name for a value. Python works out the type from the value:

```python
name = "Amaka"      # str (text)
age = 16            # int (whole number)
height = 1.62       # float (decimal)
is_prefect = True   # bool (True or False)
```

Names use lowercase letters and underscores: `exam_score`, not `Exam Score`.

## Input

`input()` waits for the person to type a line and gives it back **as text**. Convert it before doing maths:

```python
name = input("What is your name? ")
age = int(input("How old are you? "))
print("Next year you will be", age + 1)
```

> In the Code studio, type your answers in the **Input** box, one line for each `input()`.

## Putting values into text

An **f-string** (an `f` before the quote) puts values straight into text:

```python
score = 78
print(f"{name} scored {score} out of 100.")
```

## Arithmetic

| Operator | Meaning | Example | Result |
|---|---|---|---|
| `+ - *` | add, subtract, multiply | `7 * 3` | `21` |
| `/` | divide (always a float) | `7 / 2` | `3.5` |
| `//` | whole-number division | `7 // 2` | `3` |
| `%` | remainder | `7 % 2` | `1` |
| `**` | power | `2 ** 5` | `32` |

## Errors are normal

Python tells you the kind of error and the line. `NameError` usually means a misspelt variable; `TypeError` often means you mixed text and numbers (`"5" + 3`). Read the message, fix one thing, run again.
