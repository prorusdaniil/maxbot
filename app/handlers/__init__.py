from .callbacks import router as callbacks_router
from .messages import router as messages_router

routers = (messages_router, callbacks_router)
