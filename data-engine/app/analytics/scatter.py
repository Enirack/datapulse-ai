"""
Scatter plot pair selection and point sampling.

Pair selection is deterministic: pairs are ranked by |correlation|
descending (a pair with an undefined/null correlation always sorts last),
ties broken by column order, then the top `max_pairs` are kept.

Point sampling uses a fixed random seed (`SAMPLE_RANDOM_STATE`) so the
same source data always produces the same sampled points — required for
reproducibility. Sampling only ever affects which points are *drawn on the
chart*; it is never applied to, or reported as, a transformation of the
underlying dataset.
"""

import math

import pandas as pd

from app.analytics.contract import ScatterChart, ScatterPoint

SAMPLE_RANDOM_STATE = 42


def select_scatter_pairs(
    columns: list[str],
    matrix: list[list["float | None"]],
    max_pairs: int,
) -> list[tuple[str, str, "float | None"]]:
    pairs: list[tuple[str, str, "float | None"]] = []
    for i in range(len(columns)):
        for j in range(i + 1, len(columns)):
            pairs.append((columns[i], columns[j], matrix[i][j]))

    pairs.sort(key=lambda pair: (math.inf if pair[2] is None else -abs(pair[2]), pair[0], pair[1]))

    return pairs[:max_pairs]


def build_scatter_chart(
    df: pd.DataFrame,
    x_column: str,
    y_column: str,
    correlation: "float | None",
    max_points: int,
) -> ScatterChart:
    paired = df[[x_column, y_column]].apply(pd.to_numeric, errors="coerce").dropna()
    paired = paired[paired.apply(lambda row: math.isfinite(row[x_column]) and math.isfinite(row[y_column]), axis=1)] if len(paired) > 0 else paired
    total_points = len(paired)

    sampled_df = paired.sample(n=max_points, random_state=SAMPLE_RANDOM_STATE) if total_points > max_points else paired

    points = [ScatterPoint(x=float(x), y=float(y)) for x, y in zip(sampled_df[x_column], sampled_df[y_column])]

    return ScatterChart(
        xColumn=x_column,
        yColumn=y_column,
        correlation=correlation,
        points=points,
        totalPoints=total_points,
        sampled=total_points > max_points,
        sampleSize=len(points),
    )
