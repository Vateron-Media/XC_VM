import PTN, sys, json

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
                rOutput[rID] = PTN.parse(rJSON[rID])
            print(json.dumps(rOutput))
        else:
            print(json.dumps(PTN.parse(rInput)))
    else:
        print(json.dumps({}))
