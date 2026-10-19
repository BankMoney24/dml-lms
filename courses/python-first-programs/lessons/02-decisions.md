## if, elif and else

A program chooses what to do with `if`. The lines that belong to it are **indented** (four spaces is the convention):

```python
score = int(input("Score: "))

if score >= 70:
    print("A")
elif score >= 60:
    print("B")
elif score >= 50:
    print("C")
else:
    print("Keep practising")
```

Python checks the conditions **from the top** and runs only the first one that is true. That is why the order matters: put the highest band first.

## Comparisons

`==` equal, `!=` not equal, `<`, `<=`, `>`, `>=`. One `=` stores a value; two `==` compares.

## and, or, not

Combine conditions:

```python
if age >= 16 and has_consent:
    print("You can register")

if day == "Saturday" or day == "Sunday":
    print("Weekend")

if not paid:
    print("Please pay first")
```

## Remainders make good tests

`n % 2 == 0` is true for even numbers. `year % 4 == 0` is true when the year divides by 4. You will need this in the lab.

## Test the edges

Most mistakes hide on the **boundaries**. If A starts at 70, test 69 and 70, not just 85. In the leap-year lab, test 1900 and 2000: both divide by 100, but only one divides by 400.
