import sys, json
from guessit import guessit
from guessit.jsonutils import GuessitEncoder

if __name__ == "__main__":
    if len(sys.argv) > 1:
        rInput = sys.argv[1]
        try:
            rJSON = json.loads(rInput)
        except:
            rJSON = None
        if rJSON:
            rOutput = {}
            for rID in rJSON:
                rOutput[rID] = guessit(rJSON[rID])
            print(json.dumps(rOutput, cls=GuessitEncoder, ensure_ascii=False))
        else:
            print(json.dumps(guessit(rInput), cls=GuessitEncoder, ensure_ascii=False))
    else:
        print(json.dumps({}))
