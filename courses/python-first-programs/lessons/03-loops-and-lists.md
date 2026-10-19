## Lists

A list holds several values in order:

```python
scores = [72, 58, 91, 64]
print(scores[0])     # 72, the first item (counting starts at 0)
print(len(scores))   # 4
scores.append(80)    # add to the end
```

## for loops

`for` runs the same lines once for each item:

```python
total = 0
for s in scores:
    total = total + s
print("Average:", total / len(scores))
```

`range()` gives a run of numbers: `range(1, 13)` is 1 to 12 (it stops **before** the second number).

```python
for i in range(1, 13):
    print(f"7 x {i} = {7 * i}")
```

## while loops

`while` repeats as long as a condition is true. Make sure something changes, or it never stops:

```python
balance = 1000
months = 0
while balance < 2000:
    balance = balance * 1.1
    months += 1
print(months, "months to double")
```

> The Code studio stops a program that runs for more than a few seconds, so an endless loop cannot freeze your computer.

## Useful built-ins

`sum(scores)`, `max(scores)`, `min(scores)`, `sorted(scores)`, `len(scores)`.

## Dictionaries

A dictionary stores values under names (keys):

```python
learner = {"name": "Tobi", "score": 67}
print(learner["name"])
learner["score"] = 70
```
