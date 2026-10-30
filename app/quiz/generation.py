from llm import LLMClient

from .models import QuizState
from .parsing import parse_quiz

QUIZ_SYSTEM_PROMPT = (
    "Ты — учитель. По тексту параграфа составь один тестовый вопрос и 4 варианта ответа. "
    "Правильных вариантов может быть один или несколько (но не все). "
    "Используй только факты из текста. Ответь строго JSON без пояснений и markdown: "
    '{"question": "...", "options": ["...", "...", "...", "..."], '
    '"correct": [номера правильных вариантов, начиная с 0]}'
)


async def generate_quiz(llm: LLMClient, paragraph_text: str) -> QuizState:
    raw = await llm.complete(QUIZ_SYSTEM_PROMPT, paragraph_text)
    return parse_quiz(raw)
